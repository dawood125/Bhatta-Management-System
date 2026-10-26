<?php

namespace App\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Puts a backup back: unpacks the zip (with the backup password), empties the target database,
 * loads the dump into it with the database's own client, and, if asked, puts the uploaded files
 * back. The live database is only ever touched when the caller says so explicitly.
 */
class BackupRestorer
{
    public const TARGET_CONNECTION = 'backup_target';

    /**
     * @param  array{disk: string, path: string, file: string}  $backup
     * @return array{tables: int}
     */
    public function restore(array $backup, string $database, bool $withFiles = false, bool $intoLiveDatabase = false): array
    {
        $this->guard($database, $intoLiveDatabase);

        $dir = storage_path('app/backup-temp/restore-'.Str::random(16));
        File::ensureDirectoryExists($dir, 0700);

        try {
            $zipPath = $dir.'/backup.zip';
            $source = Storage::disk($backup['disk'])->readStream($backup['path']);
            $target = fopen($zipPath, 'wb');
            if (! is_resource($source) || $target === false) {
                throw new RuntimeException("The backup {$backup['file']} cannot be read.");
            }
            stream_copy_to_stream($source, $target);
            fclose($target);
            fclose($source);

            [$dump, $files] = $this->unpack($zipPath, $dir, $withFiles);

            $this->emptyDatabase($database, $intoLiveDatabase);
            $this->import($dump, $database);

            if ($withFiles && $files) {
                File::copyDirectory($dir.'/public', storage_path('app/public'));
            }

            return ['tables' => count($this->tables($database))];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Drop every table and view of the database. */
    public function emptyDatabase(string $database, bool $intoLiveDatabase = false): void
    {
        $this->guard($database, $intoLiveDatabase);
        $connection = $this->connection($database);

        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($connection->select('SHOW FULL TABLES') as $row) {
                $values = array_values((array) $row);
                $keyword = $values[1] === 'VIEW' ? 'VIEW' : 'TABLE';
                $connection->statement("DROP {$keyword} IF EXISTS `".str_replace('`', '``', (string) $values[0]).'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @return list<string> */
    public function tables(string $database): array
    {
        return array_map(
            fn ($row) => (string) $row->name,
            DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$database]),
        );
    }

    /** A connection like the live one, to another database on the same server. */
    public function connection(string $database, string $name = self::TARGET_CONNECTION): Connection
    {
        config(['database.connections.'.$name => array_merge($this->liveConfig(), ['database' => $database])]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function guard(string $database, bool $intoLiveDatabase): void
    {
        if ($database === '') {
            throw new RuntimeException('No database to restore into.');
        }
        if ($database === DB::connection()->getDatabaseName() && ! $intoLiveDatabase) {
            throw new RuntimeException('Refusing to overwrite the live database.');
        }
    }

    /**
     * Extract the database dump (and the uploaded files) into the folder.
     *
     * @return array{0: string, 1: bool} the dump's path, and whether there were files
     */
    private function unpack(string $zipPath, string $dir, bool $withFiles): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The backup file is damaged and cannot be opened.');
        }

        try {
            $password = config('backup.backup.password');
            if (filled($password)) {
                $zip->setPassword((string) $password);
            }

            $dumps = [];
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (in_array('..', explode('/', $name), true) || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new RuntimeException("The backup contains an unsafe path: {$name}");
                }
                if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                    $dumps[] = $name;
                } elseif (str_starts_with($name, 'public/') && ! str_ends_with($name, '/')) {
                    $files[] = $name;
                }
            }

            if (count($dumps) !== 1) {
                throw new RuntimeException('The backup does not contain exactly one database dump.');
            }

            if (! $zip->extractTo($dir, array_merge($dumps, $withFiles ? $files : []))) {
                throw new RuntimeException('The backup cannot be unpacked. Is BACKUP_ARCHIVE_PASSWORD the one it was made with?');
            }
        } finally {
            $zip->close();
        }

        return [$dir.'/'.$dumps[0], $files !== []];
    }

    private function import(string $dump, string $database): void
    {
        $config = $this->liveConfig();
        $options = $dump.'.cnf';

        $lines = ['[client]', 'user='.$this->quote((string) ($config['username'] ?? ''))];
        if (filled($config['password'] ?? null)) {
            $lines[] = 'password='.$this->quote((string) $config['password']);
        }
        if (filled($config['unix_socket'] ?? null)) {
            $lines[] = 'socket='.$this->quote((string) $config['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) ($config['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        $lines[] = 'default-character-set=utf8mb4';

        File::put($options, implode("\n", $lines)."\n");
        chmod($options, 0600);

        try {
            $input = fopen($dump, 'rb');
            $process = new Process([(string) config('backup.restore_client', 'mysql'), '--defaults-extra-file='.$options, $database]);
            $process->setInput($input);
            $process->setTimeout(3600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Loading the backup into the database failed: '.Str::limit(trim($process->getErrorOutput()), 500));
            }
        } finally {
            File::delete($options);
        }
    }

    /** @return array<string, mixed> */
    private function liveConfig(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
