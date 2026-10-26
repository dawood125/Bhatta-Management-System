<?php

namespace App\Backups;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;

/**
 * The backups kept on every backup disk (config/backup.php), newest first, and whether they are
 * healthy: the newest is at most a day old (with two hours' grace for the nightly run).
 */
class BackupCatalog
{
    public const HEALTHY_HOURS = 26;

    /** @return list<string> */
    public function disks(): array
    {
        return array_values((array) config('backup.backup.destination.disks', []));
    }

    public function name(): string
    {
        return (string) config('backup.backup.name');
    }

    public function isEncrypted(): bool
    {
        return filled(config('backup.backup.password'));
    }

    /** @return list<string> */
    public function alertEmails(): array
    {
        return array_values((array) config('backup.notifications.mail.to', []));
    }

    /**
     * @return Collection<int, array{disk: string, path: string, file: string, date: Carbon, size: int}>
     */
    public function backups(): Collection
    {
        $backups = [];
        foreach ($this->disks() as $disk) {
            try {
                foreach (BackupDestination::create($disk, $this->name())->backups() as $backup) {
                    $backups[] = [
                        'disk' => $disk,
                        'path' => $backup->path(),
                        'file' => basename($backup->path()),
                        'date' => Carbon::instance($backup->date()),
                        'size' => (int) $backup->sizeInBytes(),
                    ];
                }
            } catch (Throwable) {
                // An unreachable disk lists nothing; unreachableDisks() reports it.
            }
        }

        usort($backups, fn (array $a, array $b) => $b['date']->getTimestamp() <=> $a['date']->getTimestamp());

        return collect($backups);
    }

    /** @return array{disk: string, path: string, file: string, date: Carbon, size: int}|null */
    public function newest(?string $disk = null): ?array
    {
        return $this->backups()->first(fn (array $backup) => $disk === null || $backup['disk'] === $disk);
    }

    /**
     * A backup by disk and file name, only if it is one of the listed backups (so no other file can
     * ever be reached through this).
     *
     * @return array{disk: string, path: string, file: string, date: Carbon, size: int}|null
     */
    public function find(string $disk, string $file): ?array
    {
        return $this->backups()->first(fn (array $backup) => $backup['disk'] === $disk && $backup['file'] === $file);
    }

    /**
     * Disks that cannot be read right now.
     *
     * @return list<string>
     */
    public function unreachableDisks(): array
    {
        return array_values(array_filter($this->disks(), function (string $disk): bool {
            try {
                return ! BackupDestination::create($disk, $this->name())->isReachable();
            } catch (Throwable) {
                return true;
            }
        }));
    }

    public function isHealthy(): bool
    {
        $newest = $this->newest();

        return $newest !== null
            && $newest['date']->greaterThan(now()->subHours(self::HEALTHY_HOURS))
            && $this->unreachableDisks() === [];
    }
}
