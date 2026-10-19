<?php

namespace App\Support;

use App\Enums\JournalType;
use App\Models\JournalEntry;

/**
 * Readable descriptions of journal entries, built at display time so they always appear in the
 * viewer's language (only what a person typed is stored).
 */
class JournalPresenter
{
    public static function typeTone(JournalType $type): string
    {
        return match ($type) {
            JournalType::OpeningBalance => 'info',
            JournalType::Transfer => 'neutral',
            JournalType::CapitalIn => 'success',
            JournalType::CapitalOut => 'warning',
            JournalType::Reversal => 'danger',
        };
    }

    /** One-line description: the typed note, or a sentence built from the entry's accounts. */
    public static function describe(JournalEntry $entry): string
    {
        if ($entry->type !== JournalType::Reversal && filled($entry->memo)) {
            return (string) $entry->memo;
        }

        $debits = $entry->lines->filter(fn ($line) => $line->debit->isPositive())->map(fn ($line) => $line->account->displayName())->implode(', ');
        $credits = $entry->lines->filter(fn ($line) => $line->credit->isPositive())->map(fn ($line) => $line->account->displayName())->implode(', ');

        // No "default" arm on purpose: a new entry type must get its own description.
        return match ($entry->type) {
            JournalType::Reversal => __('Reversal: :reason', ['reason' => (string) $entry->memo]),
            JournalType::Transfer => __('From :from to :to', ['from' => $credits, 'to' => $debits]),
            JournalType::CapitalIn => __('Owner capital into :account', ['account' => $debits]),
            JournalType::CapitalOut => __('Owner withdrawal from :account', ['account' => $credits]),
            JournalType::OpeningBalance => __('Opening balance'),
        };
    }
}
