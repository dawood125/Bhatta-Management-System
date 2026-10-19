<?php

namespace App\Actions\Accounting;

use App\Accounting\ChartOfAccounts;
use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\AccountType;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Enums\TreasuryKind;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a cash/bank account. The opening balance is posted as a journal entry against
 * "Opening balances"; changing it later reverses the old opening entry (on its own date) and
 * posts a new one, so history stays complete.
 */
class SaveTreasuryAccount
{
    use GuardsBalances, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?TreasuryAccount $account, array $input, string $idempotencyKey): TreasuryAccount
    {
        $this->authorizeSettings($actor, Permission::AccountsManage);
        $input = $this->clean($input, ['name_ur', 'account_number']);
        $input['allow_negative'] = filter_var($input['allow_negative'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', $this->uniqueInKiln('treasury_accounts', 'name', $account?->id)],
            'name_ur' => ['nullable', 'string', 'max:100', $this->uniqueInKiln('treasury_accounts', 'name_ur', $account?->id)],
            'kind' => $account === null ? ['required', Rule::enum(TreasuryKind::class)] : ['nullable'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'opening_balance' => ['required', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'allow_negative' => ['boolean'],
        ])->validate();

        $opening = Money::fromRupees($data['opening_balance']);
        $openingDate = Carbon::createFromFormat('Y-m-d', $data['opening_date'])->startOfDay();

        if ($opening->isNegative() && ! $data['allow_negative']) {
            throw ValidationException::withMessages([
                'opening_balance' => __('A negative opening balance needs "Allow negative balance" switched on.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $account, $data, $opening, $openingDate, $idempotencyKey): TreasuryAccount {
            if ($account === null) {
                $kind = TreasuryKind::from($data['kind']);
                $ledger = $this->chart->createTreasuryLedgerAccount($kind, $data['name'], $data['name_ur']);

                $account = TreasuryAccount::query()->create([
                    'ledger_account_id' => $ledger->id,
                    'kind' => $kind,
                    'name' => $data['name'],
                    'name_ur' => $data['name_ur'],
                    'account_number' => $data['account_number'],
                    'opening_balance' => $opening,
                    'opening_date' => $openingDate->format('Y-m-d'),
                    'allow_negative' => $data['allow_negative'],
                    'is_active' => true,
                    'sort_order' => ((int) TreasuryAccount::query()->max('sort_order')) + 10,
                ]);
                $account->setRelation('ledgerAccount', $ledger);

                if (! $opening->isZero()) {
                    $account->opening_entry_id = $this->postOpening($actor, $account, $opening, $openingDate, $idempotencyKey.':open')->id;
                    $account->save();
                }

                return $account;
            }

            /** @var TreasuryAccount $account */
            $account = TreasuryAccount::query()->whereKey($account->id)->lockForUpdate()->with('ledgerAccount')->firstOrFail();

            $account->fill([
                'name' => $data['name'],
                'name_ur' => $data['name_ur'],
                'account_number' => $data['account_number'],
                'allow_negative' => $data['allow_negative'],
            ])->save();
            $account->ledgerAccount->fill(['name' => $data['name'], 'name_ur' => $data['name_ur']])->save();

            $openingChanged = ! $account->opening_balance->equals($opening)
                || $account->opening_date->format('Y-m-d') !== $openingDate->format('Y-m-d');

            if ($openingChanged) {
                $this->assertNoEntriesBefore($account, $openingDate);

                if ($account->opening_entry_id !== null) {
                    $old = JournalEntry::query()->findOrFail($account->opening_entry_id);
                    // Dated like the original so the two cancel out at the same point in history.
                    $this->poster->reverse($old, $old->entry_date, 'Opening balance changed', $idempotencyKey.':rev', $actor);
                }

                $account->opening_balance = $opening;
                $account->opening_date = $openingDate;
                $account->opening_entry_id = $opening->isZero()
                    ? null
                    : $this->postOpening($actor, $account, $opening, $openingDate, $idempotencyKey.':open')->id;
                $account->save();
            }

            $this->assertNotNegative($account, 'opening_balance');

            return $account;
        });
    }

    private function postOpening(User $actor, TreasuryAccount $account, Money $opening, Carbon $date, string $key): JournalEntry
    {
        $equity = LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY);
        $ledger = $account->ledgerAccount;
        $amount = $opening->abs();

        // A positive opening increases the account in its natural direction.
        $increase = $opening->isPositive();
        $debitAccount = ($ledger->type === AccountType::Asset) === $increase;

        $lines = $debitAccount
            ? [PendingLine::debit($ledger, $amount), PendingLine::credit($equity, $amount)]
            : [PendingLine::debit($equity, $amount), PendingLine::credit($ledger, $amount)];

        return $this->poster->post(JournalType::OpeningBalance, $date, $lines, $key, $actor);
    }

    /** Transactions dated before the opening date would make old statements wrong. */
    private function assertNoEntriesBefore(TreasuryAccount $account, Carbon $openingDate): void
    {
        $earlier = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->ledger_account_id)
            ->whereNotIn('journal_entries.type', [JournalType::OpeningBalance->value, JournalType::Reversal->value])
            ->where('journal_entries.entry_date', '<', $openingDate->format('Y-m-d'))
            ->exists();

        if ($earlier) {
            throw ValidationException::withMessages([
                'opening_date' => __('This account has transactions before this date. Choose an earlier opening date.'),
            ]);
        }
    }
}
