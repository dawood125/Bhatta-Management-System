<?php

namespace App\Actions\Vouchers;

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A payment or receipt voucher: one cash/bank account and one or more lines, each to a party
 * (customer, supplier, driver, agent) or to another of the kiln's accounts.
 *
 * Payment: money leaves the voucher's account. Each party line is debited (we owe them less, or
 * they owe us more); each account line receives the money.
 * Receipt: money enters the voucher's account. Each party line is credited (they owe us less, or
 * we owe them more); each account line gives the money.
 *
 * The whole voucher is one balanced journal entry, numbered PV-… or RCV-…. Line 1 is always the
 * voucher's own account.
 */
class RecordVoucher
{
    use GuardsBalances, ValidatesSettings;

    public const LINE_PARTY = 'party';

    public const LINE_ACCOUNT = 'account';

    public const MAX_LINES = 30;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  account_id, entry_date, memo,
     *                                       lines[] = {type, party_id, account_id, amount, memo}
     */
    public function handle(User $actor, JournalType $type, array $input, string $idempotencyKey): JournalEntry
    {
        if (! $type->isVoucher()) {
            throw new InvalidArgumentException("[{$type->value}] is not a voucher type.");
        }

        $this->authorizeSettings($actor, Permission::PaymentsRecord);

        // The same voucher form sent twice: return the voucher it already recorded.
        $existing = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();
        if ($existing !== null) {
            if ($existing->type !== $type) {
                throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
            }

            return $existing;
        }

        $input = $this->cleanInput($input);
        $data = $this->validate($input);

        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        /** @var list<array{type: string, party_id: int|null, account_id: int|null, amount: Money, memo: string|null}> $lines */
        $lines = [];
        $total = Money::zero();
        foreach (array_values($data['lines']) as $line) {
            $amount = Money::fromRupees($line['amount']);

            try {
                $total = $total->add($amount);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['lines' => __('The total of this voucher is too large.')]);
            }

            $lines[] = [
                'type' => $line['type'],
                'party_id' => $line['type'] === self::LINE_PARTY ? (int) $line['party_id'] : null,
                'account_id' => $line['type'] === self::LINE_ACCOUNT ? (int) $line['account_id'] : null,
                'amount' => $amount,
                'memo' => $line['memo'] ?? null,
            ];
        }

        return DB::transaction(function () use ($actor, $type, $data, $date, $lines, $total, $idempotencyKey): JournalEntry {
            $mainId = (int) $data['account_id'];
            $lineAccountIds = array_values(array_filter(array_column($lines, 'account_id')));
            $accounts = $this->lockTreasuryAccounts(array_values(array_unique([$mainId, ...$lineAccountIds])));
            $parties = Party::query()->whereKey(array_filter(array_column($lines, 'party_id')))->get()->keyBy('id');

            $this->assertDateAllowed($date, $accounts->all(), $parties->all());

            $isPayment = $type === JournalType::Payment;
            $main = $accounts[$mainId];

            // Line 1: the voucher's own account. Payment: money out (credit). Receipt: money in (debit).
            $pending = [$isPayment ? PendingLine::credit($main->ledgerAccount, $total) : PendingLine::debit($main->ledgerAccount, $total)];

            foreach ($lines as $line) {
                $party = $line['party_id'] !== null ? $parties[$line['party_id']] : null;
                $account = $party !== null ? $party->controlAccount() : $accounts[(int) $line['account_id']]->ledgerAccount;

                $pending[] = ($isPayment
                    ? PendingLine::debit($account, $line['amount'], $line['memo'])
                    : PendingLine::credit($account, $line['amount'], $line['memo']))->forParty($party);
            }

            $entry = $this->poster->post($type, $date, $pending, $idempotencyKey, $actor, $data['memo']);

            // Inside the same transaction: a balance that went below zero rolls the voucher back.
            $this->assertNotNegative($main, 'account_id');
            foreach ($lines as $index => $line) {
                if ($line['account_id'] !== null) {
                    $this->assertNotNegative($accounts[$line['account_id']], "lines.{$index}.account_id");
                }
            }

            return $entry;
        });
    }

    /**
     * Trim text, turn empty optional fields into null and drop the field a line's type doesn't use
     * (a party line has no account and the other way round).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function cleanInput(array $input): array
    {
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : $input['lines'] ?? null;
        $input = $this->clean(array_diff_key($input, ['lines' => true]), ['memo']);

        if (is_array($lines)) {
            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $line = $this->clean($line, ['party_id', 'account_id', 'memo']);
                $type = $line['type'] ?? null;
                if ($type === self::LINE_PARTY) {
                    $line['account_id'] = null;
                } elseif ($type === self::LINE_ACCOUNT) {
                    $line['party_id'] = null;
                }

                $lines[$index] = $line;
            }
        }

        $input['lines'] = $lines;

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $kilnId = $this->kilnId();
        $activeAccount = Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true);
        $activeParty = Rule::exists('parties', 'id')->where('kiln_id', $kilnId)->where('is_active', true);

        return Validator::make($input, [
            'account_id' => ['required', 'integer', $activeAccount],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.type' => ['required', Rule::in([self::LINE_PARTY, self::LINE_ACCOUNT])],
            'lines.*.party_id' => ['nullable', 'required_if:lines.*.type,'.self::LINE_PARTY, 'integer', $activeParty],
            'lines.*.account_id' => ['nullable', 'required_if:lines.*.type,'.self::LINE_ACCOUNT, 'integer', $activeAccount, 'different:account_id'],
            'lines.*.amount' => ['required', new MoneyAmount],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.required' => __('Add at least one line.'),
            'lines.min' => __('Add at least one line.'),
            'lines.max' => __('A voucher can have at most :max lines.'),
            'lines.*.party_id.required_if' => __('Choose a party.'),
            'lines.*.party_id.exists' => __('This party is archived or does not exist.'),
            'lines.*.account_id.required_if' => __('Choose an account.'),
            'lines.*.account_id.exists' => __('This account is archived or does not exist.'),
            'lines.*.account_id.different' => __('Choose an account other than the voucher\'s own account.'),
            'account_id.exists' => __('This account is archived or does not exist.'),
        ], [
            'account_id' => __('account'),
            'entry_date' => __('date'),
            'memo' => __('note'),
            'lines.*.type' => __('type'),
            'lines.*.amount' => __('amount'),
            'lines.*.memo' => __('remarks'),
        ])->validate();
    }

    /**
     * Nothing may be dated before the opening date of an account or party it touches, or the
     * opening balances shown on statements would be wrong.
     *
     * @param  array<int, TreasuryAccount>  $accounts
     * @param  array<int, Party>  $parties
     */
    private function assertDateAllowed(Carbon $date, array $accounts, array $parties): void
    {
        foreach ($accounts as $account) {
            if ($date->lt($account->opening_date)) {
                throw ValidationException::withMessages(['entry_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $account->displayName(),
                    'date' => $account->opening_date->format('d-m-Y'),
                ])]);
            }
        }

        foreach ($parties as $party) {
            if ($date->lt($party->opening_date)) {
                throw ValidationException::withMessages(['entry_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $party->displayName(),
                    'date' => $party->opening_date->format('d-m-Y'),
                ])]);
            }
        }
    }
}
