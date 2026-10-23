<?php

namespace App\Models\Concerns;

use App\Accounting\LedgerIntegrityException;

/**
 * For records that sit behind a journal entry: they are written once and never changed or
 * deleted. A mistake is corrected by reversing the entry.
 */
trait WriteOnce
{
    public static function bootWriteOnce(): void
    {
        static::updating(function (): void {
            throw new LedgerIntegrityException(class_basename(static::class).' records cannot be changed; reverse them instead.');
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException(class_basename(static::class).' records can never be deleted; reverse them instead.');
        });
    }
}
