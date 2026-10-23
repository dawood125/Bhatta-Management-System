<?php

namespace App\Actions\Expenses;

use App\Accounting\ChartOfAccounts;
use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\Expense;
use App\Models\ExpenseCategory;
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
 * Record a kiln expense paid from a cash/bank account: debit the category's expense account,
 * credit the cash/bank account (entry EX-…). The category and sub-category are kept for reports.
 */
class RecordExpense
{
    use GuardsBalances, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ChartOfAccounts $chartOfAccounts,
    ) {}

    /**
     * @param  array<string, mixed>  $input  account_id, entry_date, expense_category_id, expense_sub_category_id, amount, description
     */
    public function handle(User $actor, array $input, string $idempotencyKey): Expense
    {
        $this->authorizeSettings($actor, Permission::ExpensesRecord);

        // The same expense form sent twice: return the expense it already recorded.
        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['expense_sub_category_id', 'description']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'account_id' => ['required', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'expense_sub_category_id' => [
                'nullable', 'integer',
                Rule::exists('expense_sub_categories', 'id')->where('kiln_id', $kilnId)->where('is_active', true)
                    ->where('expense_category_id', (int) ($input['expense_category_id'] ?? 0)),
            ],
            'amount' => ['required', new MoneyAmount],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'account_id.exists' => __('This account is archived or does not exist.'),
            'expense_category_id.exists' => __('This category is archived or does not exist.'),
            'expense_sub_category_id.exists' => __('Choose a sub-category of the selected category.'),
        ], [
            'account_id' => __('account'),
            'entry_date' => __('date'),
            'expense_category_id' => __('category'),
            'expense_sub_category_id' => __('sub-category'),
        ])->validate();

        $amount = Money::fromRupees($data['amount']);
        $date = Carbon::createFromFormat('Y-m-d', $data['entry_date'])->startOfDay();

        return DB::transaction(function () use ($actor, $data, $amount, $date, $idempotencyKey): Expense {
            $treasury = $this->lockTreasuryAccounts([(int) $data['account_id']])->sole();

            if ($date->lt($treasury->opening_date)) {
                throw ValidationException::withMessages(['entry_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $treasury->displayName(),
                    'date' => $treasury->opening_date->format('d-m-Y'),
                ])]);
            }

            $category = ExpenseCategory::query()->findOrFail((int) $data['expense_category_id']);
            $expenseAccount = $this->chartOfAccounts->expenseAccountFor($category);

            $entry = $this->poster->post(
                JournalType::Expense,
                $date,
                [PendingLine::debit($expenseAccount, $amount), PendingLine::credit($treasury->ledgerAccount, $amount)],
                $idempotencyKey,
                $actor,
                $data['description'],
            );

            // Two identical requests raced each other and the other one posted first.
            if (! $entry->wasRecentlyCreated) {
                return Expense::query()->where('journal_entry_id', $entry->id)->firstOrFail();
            }

            $this->assertNotNegative($treasury, 'amount');

            return Expense::query()->create([
                'journal_entry_id' => $entry->id,
                'expense_date' => $date->format('Y-m-d'),
                'expense_category_id' => $category->id,
                'expense_sub_category_id' => $data['expense_sub_category_id'] !== null ? (int) $data['expense_sub_category_id'] : null,
                'treasury_account_id' => $treasury->id,
                'amount' => $amount,
                'description' => $data['description'],
                'created_by' => $actor->id,
            ]);
        });
    }

    private function findByKey(string $idempotencyKey): ?Expense
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::Expense) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return Expense::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
