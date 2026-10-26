<?php

namespace App\Actions\Sales;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Write off what a customer will never pay (bad debt), by the owner only (proposed default for
 * Q-31): the amount leaves the customer's account and becomes the expense "Bad debts written
 * off" (WO-…). At most what the customer owes on that day; a reason is required. Reversible from
 * the transactions screen if the customer pays after all.
 */
class WriteOffBalance
{
    use ChecksSales, ValidatesSettings;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  entry_date, amount, reason
     */
    public function handle(User $actor, Party $customer, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::SalesWriteOff);

        $existing = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();
        if ($existing !== null && $existing->type === JournalType::BadDebt) {
            return $existing;
        }

        $data = Validator::make($this->clean($input, []), [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], [
            'entry_date' => __('date'),
            'amount' => __('amount'),
            'reason' => __('reason'),
        ])->validate();

        if (! $customer->hasRole(PartyRole::Customer)) {
            throw ValidationException::withMessages(['amount' => __('Only what customers owe can be written off.')]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $amount = Money::fromRupees((string) $data['amount']);
        $this->assertAfterOpening($customer, $date, 'entry_date');

        return DB::transaction(function () use ($actor, $customer, $date, $amount, $data, $idempotencyKey): JournalEntry {
            // One write-off at a time per customer, so two can't both take the same balance.
            Party::query()->whereKey($customer->id)->lockForUpdate()->first();

            $owed = $customer->balance($date->copy()->endOfDay());
            if (! $owed->isPositive()) {
                throw ValidationException::withMessages(['amount' => __('This customer owes nothing on that day.')]);
            }

            if ($amount->greaterThan($owed)) {
                throw ValidationException::withMessages(['amount' => __('At most what the customer owes on that day (:amount).', ['amount' => $owed->formatWithCurrency()])]);
            }

            return $this->poster->post(JournalType::BadDebt, $date, [
                PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::BAD_DEBTS), $amount),
                PendingLine::credit($customer->controlAccount(), $amount)->forParty($customer),
            ], $idempotencyKey, $actor, $data['reason']);
        });
    }
}
