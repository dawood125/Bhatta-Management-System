<?php

namespace App\Backups;

use App\Accounting\LedgerVerifier;
use App\Enums\Role;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Puts a backup back from the Backups page, by the owner (client, 2026-11-25). The same steps
 * as `php artisan kiln:restore-backup`: a safety backup of the database as it is now, the site
 * in maintenance mode, the backup restored with its files, the database brought up to this
 * version, and the books checked.
 *
 * Only backups already on the server or on Google Drive can be restored, never an uploaded
 * file: those were made by the system itself, so they can be trusted.
 *
 * It replaces the whole database of this installation (each customer has their own server).
 */
class WebRestore
{
    /** What the owner types to confirm. */
    public const PHRASE = 'RESTORE';

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupRunner $runner,
        private readonly BackupRestorer $restorer,
        private readonly LedgerVerifier $verifier,
        private readonly KilnAccess $access,
        private readonly CurrentKiln $currentKiln,
    ) {}

    /**
     * @param  array<string, mixed>  $input  disk, file, password, phrase
     * @return array{file: string, safety: string|null, problems: list<string>}
     */
    public function handle(User $actor, array $input): array
    {
        if ($this->access->roleIn($actor, $this->currentKiln->get()) !== Role::Owner) {
            abort(403);
        }

        $backup = $this->catalog->find((string) ($input['disk'] ?? ''), (string) ($input['file'] ?? ''));
        if ($backup === null) {
            throw ValidationException::withMessages(['file' => __('This backup no longer exists.')]);
        }

        if (! Hash::check((string) ($input['password'] ?? ''), $actor->password)) {
            throw ValidationException::withMessages(['password' => __('The password is not correct.')]);
        }

        if (strtoupper(trim((string) ($input['phrase'] ?? ''))) !== self::PHRASE) {
            throw ValidationException::withMessages(['phrase' => __('Type :phrase exactly to confirm.', ['phrase' => self::PHRASE])]);
        }

        @set_time_limit(1800);

        // The database as it is now, so this restore can be undone.
        $safety = $this->runner->run($actor);
        if (! $safety->succeeded()) {
            throw ValidationException::withMessages(['file' => __('The safety backup failed, so nothing was restored: :message', ['message' => (string) $safety->message])]);
        }

        Artisan::call('down', ['--retry' => 60]);

        try {
            $this->restorer->restore($backup, DB::connection()->getDatabaseName(), withFiles: true, intoLiveDatabase: true);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } catch (Throwable $e) {
            report($e);

            // Like the command: the site stays closed, so nobody works on a half-restored database.
            throw ValidationException::withMessages(['file' => __('The restore failed and the site is closed for maintenance. Ask your developer to restore the safety backup :file. (:error)', [
                'file' => basename((string) $safety->path),
                'error' => $e->getMessage(),
            ])]);
        }

        $problems = $this->verifier->verify();
        Artisan::call('up');

        activity()
            ->causedBy($actor)
            ->event('backup_restored')
            ->withProperties(['attributes' => ['file' => $backup['file'], 'safety' => $safety->path ? basename($safety->path) : null, 'problems' => count($problems)]])
            ->log('backup_restored');

        return ['file' => $backup['file'], 'safety' => $safety->path ? basename($safety->path) : null, 'problems' => $problems];
    }
}
