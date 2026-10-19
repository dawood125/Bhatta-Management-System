<?php

namespace App\Actions\Accounting;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Move money between two of the kiln's own accounts (e.g. bank -> cash in hand).
 */
class RecordTransfer
{
    use GuardsBalances, ValidatesSettings;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  from_account_id, to_account_id, amount, entry_date, memo
     */
    public function handle(User $actor, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::TransactionsRecord);
        $input = $this->clean($input, ['memo']);
        $kilnId = $this->kilnId();

        $activeAccount = Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true);

        $data = Validator::make($input, [
            'from_account_id' => ['required', 'integer', $activeAccount],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $activeAccount],
            'amount' => ['required', new MoneyAmount],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'memo' => ['nullable', 'string', 'max:255'],
        ], [
            'to_account_id.different' => __('Choose two different accounts.'),
        ])->validate();

        $amount = Money::fromRupees($data['amount']);
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        return DB::transaction(function () use ($actor, $data, $amount, $date, $idempotencyKey): JournalEntry {
            $accounts = $this->lockTreasuryAccounts([(int) $data['from_account_id'], (int) $data['to_account_id']]);
            $from = $accounts[(int) $data['from_account_id']];
            $to = $accounts[(int) $data['to_account_id']];

            foreach ([$from, $to] as $account) {
                if ($date->lt($account->opening_date)) {
                    throw ValidationException::withMessages([
                        'entry_date' => __('The date is before the opening date of :account (:date).', [
                            'account' => $account->displayName(),
                            'date' => $account->opening_date->format('d-m-Y'),
                        ]),
                    ]);
                }
            }

            $entry = $this->poster->post(
                JournalType::Transfer,
                $date,
                [PendingLine::debit($to->ledgerAccount, $amount), PendingLine::credit($from->ledgerAccount, $amount)],
                $idempotencyKey,
                $actor,
                $data['memo'],
            );

            $this->assertNotNegative($from, 'amount');
            $this->assertNotNegative($to, 'amount');

            return $entry;
        });
    }
}
