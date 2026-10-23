<?php

namespace App\Actions\Accounting;

use App\Accounting\JournalPoster;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a transaction by posting its mirror image (dated today). The original stays visible,
 * marked "reversed", together with the reason.
 */
class ReverseEntry
{
    use GuardsBalances, ValidatesSettings;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, JournalEntry $entry, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::TransactionsReverse);
        $input = $this->clean($input, []);

        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ])->validate();

        if (! $entry->type->isUserReversible()) {
            throw ValidationException::withMessages(['reason' => __('This kind of entry cannot be reversed here.')]);
        }

        // Wages earned with a production entry go with that entry.
        $production = WorkEntry::query()->where('journal_entry_id', $entry->id)->whereNotNull('production_entry_id')->with('productionEntry')->first();
        if ($production !== null) {
            throw ValidationException::withMessages(['reason' => __('These wages were earned with production entry :number. Reverse that entry instead.', ['number' => $production->productionEntry?->number])]);
        }

        return DB::transaction(function () use ($actor, $entry, $data, $idempotencyKey): JournalEntry {
            $ledgerIds = $entry->lines()->pluck('ledger_account_id')->all();
            $treasuryIds = TreasuryAccount::query()->whereIn('ledger_account_id', $ledgerIds)->pluck('id')->all();
            $accounts = $this->lockTreasuryAccounts($treasuryIds);

            $fresh = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isReversed()) {
                $existing = JournalEntry::query()->where('reversal_of_id', $fresh->id)->first();
                if ($existing !== null && $existing->idempotency_key === trim($idempotencyKey)) {
                    return $existing; // the same request arrived twice
                }

                throw ValidationException::withMessages(['reason' => __('This entry has already been reversed.')]);
            }

            $reversal = $this->poster->reverse($fresh, now(), $data['reason'], $idempotencyKey, $actor);

            foreach ($accounts as $account) {
                $this->assertNotNegative($account, 'reason');
            }

            return $reversal;
        });
    }
}
