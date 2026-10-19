<?php

namespace App\Actions\Accounting;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owner puts their own money into the kiln (capital in) or takes money out for personal use
 * (withdrawal). Owner only.
 */
class RecordCapital
{
    use GuardsBalances, ValidatesSettings;

    public const IN = 'in';

    public const OUT = 'out';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  direction (in|out), account_id, amount, entry_date, memo
     */
    public function handle(User $actor, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::CapitalRecord);
        $input = $this->clean($input, ['memo']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'direction' => ['required', Rule::in([self::IN, self::OUT])],
            'account_id' => ['required', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'amount' => ['required', new MoneyAmount],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'memo' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $amount = Money::fromRupees($data['amount']);
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();
        $isIn = $data['direction'] === self::IN;

        return DB::transaction(function () use ($actor, $data, $amount, $date, $isIn, $idempotencyKey): JournalEntry {
            $account = $this->lockTreasuryAccounts([(int) $data['account_id']])->first();

            if ($date->lt($account->opening_date)) {
                throw ValidationException::withMessages([
                    'entry_date' => __('The date is before the opening date of :account (:date).', [
                        'account' => $account->displayName(),
                        'date' => $account->opening_date->format('d-m-Y'),
                    ]),
                ]);
            }

            $lines = $isIn
                ? [PendingLine::debit($account->ledgerAccount, $amount), PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::OWNER_CAPITAL), $amount)]
                : [PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::OWNER_DRAWINGS), $amount), PendingLine::credit($account->ledgerAccount, $amount)];

            $entry = $this->poster->post(
                $isIn ? JournalType::CapitalIn : JournalType::CapitalOut,
                $date,
                $lines,
                $idempotencyKey,
                $actor,
                $data['memo'],
            );

            $this->assertNotNegative($account, 'amount');

            return $entry;
        });
    }
}
