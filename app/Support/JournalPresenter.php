<?php

namespace App\Support;

use App\Enums\JournalType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;

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
            JournalType::CapitalIn, JournalType::Receipt => 'success',
            JournalType::CapitalOut, JournalType::Payment => 'warning',
            JournalType::Expense, JournalType::MaterialUsage => 'brand',
            JournalType::MaterialPurchase => 'info',
            JournalType::MaterialAdjustment => 'warning',
            JournalType::Reversal => 'danger',
        };
    }

    /** One-line description: the typed note, or a sentence built from the entry's accounts and parties. */
    public static function describe(JournalEntry $entry): string
    {
        // Loads nothing when the caller already eager-loaded the lines (lists should).
        $entry->loadMissing(['lines.account', 'lines.party']);

        $debits = self::names($entry, debit: true);
        $credits = self::names($entry, debit: false);

        // An expense always names its category, and materials used name their "... used" accounts;
        // the typed details follow.
        if ($entry->type === JournalType::Expense || $entry->type === JournalType::MaterialUsage) {
            return filled($entry->memo) ? $debits.': '.$entry->memo : $debits;
        }

        // Opening stock is credited to "Opening balances"; a shortage is debited to "Material shortages".
        if ($entry->type === JournalType::MaterialAdjustment) {
            $isOpening = $entry->lines->contains(fn (JournalLine $line) => $line->credit->isPositive() && $line->account->system_key === LedgerAccount::OPENING_BALANCE_EQUITY);
            $label = $isOpening ? __('Opening material stock') : __('Material shortage');

            return filled($entry->memo) ? $label.': '.$entry->memo : $label;
        }

        if ($entry->type !== JournalType::Reversal && filled($entry->memo)) {
            return (string) $entry->memo;
        }

        // No "default" arm on purpose: a new entry type must get its own description.
        return match ($entry->type) {
            JournalType::Reversal => __('Reversal: :reason', ['reason' => (string) $entry->memo]),
            JournalType::Transfer => __('From :from to :to', ['from' => $credits, 'to' => $debits]),
            JournalType::CapitalIn => __('Owner capital into :account', ['account' => $debits]),
            JournalType::CapitalOut => __('Owner withdrawal from :account', ['account' => $credits]),
            JournalType::OpeningBalance => __('Opening balance'),
            JournalType::Payment => __('Paid to :names', ['names' => $debits]),
            JournalType::Receipt => __('Received from :names', ['names' => $credits]),
            JournalType::MaterialPurchase => __('Material purchase: :names', ['names' => $credits]),
        };
    }

    /** The party on a line, or else its account. */
    public static function lineName(JournalLine $line): string
    {
        return $line->party_id !== null && $line->party !== null ? $line->party->displayName() : $line->account->displayName();
    }

    private static function names(JournalEntry $entry, bool $debit): string
    {
        return $entry->lines
            ->filter(fn (JournalLine $line) => $debit ? $line->debit->isPositive() : $line->credit->isPositive())
            ->map(fn (JournalLine $line) => self::lineName($line))
            ->unique()
            ->implode(', ');
    }
}
