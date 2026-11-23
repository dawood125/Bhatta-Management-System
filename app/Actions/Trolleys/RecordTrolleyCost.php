<?php

namespace App\Actions\Trolleys;

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Trolley;
use App\Models\TrolleyCost;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Diesel or maintenance of a trolley (entry TC-…). It comes out of what the kiln owes the driver:
 * debit the driver; credit the cash/bank account that paid, or the supplier (e.g. a pump) who is
 * owed. It is neither a kiln expense nor income, because the driver keeps what remains.
 */
class RecordTrolleyCost
{
    use GuardsBalances, ValidatesSettings;

    public const FROM_ACCOUNT = 'account';

    public const ON_CREDIT = 'credit';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  trolley_id, cost_date, kind, amount, paid_from, account_id, supplier_id, description
     */
    public function handle(User $actor, array $input, string $idempotencyKey): TrolleyCost
    {
        $this->authorizeSettings($actor, Permission::ExpensesRecord);

        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['account_id', 'supplier_id', 'description']);
        $kilnId = $this->kilnId();
        $onCredit = ($input['paid_from'] ?? null) === self::ON_CREDIT;

        $data = Validator::make($input, [
            'trolley_id' => ['required', 'integer', Rule::exists('trolleys', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'cost_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(array_keys(TrolleyCost::kinds()))],
            'amount' => ['required', new MoneyAmount],
            'paid_from' => ['required', Rule::in([self::FROM_ACCOUNT, self::ON_CREDIT])],
            'account_id' => [$onCredit ? 'nullable' : 'required', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'supplier_id' => [$onCredit ? 'required' : 'nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'trolley_id.exists' => __('This trolley is archived or does not exist.'),
            'account_id.exists' => __('This account is archived or does not exist.'),
        ], [
            'trolley_id' => __('trolley'),
            'cost_date' => __('date'),
            'account_id' => __('account'),
            'supplier_id' => __('supplier'),
        ])->validate();

        $amount = Money::fromRupees($data['amount']);
        $date = Carbon::createFromFormat('Y-m-d', $data['cost_date'])->startOfDay();
        $trolley = Trolley::query()->with('driver')->findOrFail((int) $data['trolley_id']);
        $driver = $trolley->driver;

        $supplier = null;
        if ($onCredit) {
            $supplier = Party::query()->withRole(PartyRole::Supplier)->where('is_active', true)->find((int) $data['supplier_id']);
            if ($supplier === null) {
                throw ValidationException::withMessages(['supplier_id' => __('Choose an active party with the supplier role.')]);
            }
            if ($supplier->id === $driver->id) {
                throw ValidationException::withMessages(['supplier_id' => __('The supplier cannot be the driver of this trolley.')]);
            }
        }

        return DB::transaction(function () use ($actor, $data, $amount, $date, $trolley, $driver, $supplier, $onCredit, $idempotencyKey): TrolleyCost {
            $treasury = $onCredit ? null : $this->lockTreasuryAccounts([(int) $data['account_id']])->sole();

            if ($treasury !== null && $date->lt($treasury->opening_date)) {
                throw ValidationException::withMessages(['cost_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $treasury->displayName(),
                    'date' => $treasury->opening_date->format('d-m-Y'),
                ])]);
            }

            $credit = $treasury !== null
                ? PendingLine::credit($treasury->ledgerAccount, $amount)
                : PendingLine::credit($supplier->controlAccount(), $amount)->forParty($supplier);

            $entry = $this->poster->post(JournalType::TrolleyCost, $date, [
                PendingLine::debit($driver->controlAccount(), $amount)->forParty($driver),
                $credit,
            ], $idempotencyKey, $actor, $data['description']);

            if (! $entry->wasRecentlyCreated) {
                return TrolleyCost::query()->where('journal_entry_id', $entry->id)->firstOrFail();
            }

            if ($treasury !== null) {
                $this->assertNotNegative($treasury, 'amount');
            }

            return TrolleyCost::query()->create([
                'journal_entry_id' => $entry->id,
                'cost_date' => $date->format('Y-m-d'),
                'trolley_id' => $trolley->id,
                'driver_id' => $driver->id,
                'kind' => $data['kind'],
                'treasury_account_id' => $treasury?->id,
                'supplier_id' => $supplier?->id,
                'amount' => $amount,
                'description' => $data['description'],
                'created_by' => $actor->id,
            ]);
        });
    }

    private function findByKey(string $idempotencyKey): ?TrolleyCost
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::TrolleyCost) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return TrolleyCost::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
