<?php

namespace App\Models\Concerns;

/**
 * Master data is archived (hidden from new entries) instead of deleted, so old records that
 * refer to it keep working.
 *
 * @property bool $is_active
 */
interface Archivable
{
    /** A translated reason why this record cannot be archived right now, or null if it can. */
    public function archiveBlocker(): ?string;
}
