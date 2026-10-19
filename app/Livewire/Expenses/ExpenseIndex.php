<?php

namespace App\Livewire\Expenses;

use App\Accounting\LedgerQueries;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Expenses\RecordExpense;
use App\Enums\Permission;
use App\Livewire\Concerns\FiltersByDateRange;
use App\Livewire\Concerns\RunsActions;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kiln expenses for a period with totals per category, and the form to record a new one.
 * Also the expense report: filter, then print.
 *
 * @property-read LengthAwarePaginator<int, Expense>|null $expenses Livewire computed property
 * @property-read array{total: Money, count: int, by_category: list<array{name: string, amount: Money}>} $totals Livewire computed property
 * @property-read array<int, string> $categoryOptions Livewire computed property
 * @property-read array<int, string> $formCategoryOptions Livewire computed property
 * @property-read array<int, string> $subCategoryOptions Livewire computed property
 * @property-read array<int, string> $accountOptions Livewire computed property
 * @property-read array<int, string> $formAccountOptions Livewire computed property
 * @property-read Money|null $formAccountBalance Livewire computed property
 * @property-read Expense|null $reversing Livewire computed property
 */
class ExpenseIndex extends Component
{
    use FiltersByDateRange, RunsActions, WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $formKey = '';

    public bool $showReverseForm = false;

    public ?int $reversingId = null;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public function mount(): void
    {
        $this->authorize(Permission::AccountsView->value);
        $this->initDateRange();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'account', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }

        // A field the person has just changed no longer shows its old error.
        if (str_starts_with($property, 'form.')) {
            $this->resetErrorBag($property);
        }

        // A sub-category belongs to one category: changing the category clears it.
        if ($property === 'form.expense_category_id') {
            $this->form['expense_sub_category_id'] = '';
        }
    }

    /**
     * @return LengthAwarePaginator<int, Expense>|null
     */
    #[Computed]
    public function expenses(): ?LengthAwarePaginator
    {
        return $this->filtered()
            ?->with(['category', 'subCategory', 'treasuryAccount', 'entry', 'creator'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Totals of the filtered expenses that still count (reversed ones are left out).
     *
     * @return array{total: Money, count: int, by_category: list<array{name: string, amount: Money}>}
     */
    #[Computed]
    public function totals(): array
    {
        $rows = $this->filtered()
            ?->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->toBase()
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id, SUM(amount) AS amount, COUNT(*) AS n')
            ->get() ?? collect();

        $names = ExpenseCategory::query()->whereKey($rows->pluck('expense_category_id'))->get()->keyBy('id');

        $byCategory = $rows
            ->map(fn ($row) => ['name' => $names[(int) $row->expense_category_id]->displayName(), 'amount' => Money::fromPaisa((int) $row->amount)])
            ->sortByDesc(fn ($row) => $row['amount']->paisa)
            ->values()
            ->all();

        return [
            'total' => Money::fromPaisa((int) $rows->sum('amount')),
            'count' => (int) $rows->sum('n'),
            'by_category' => $byCategory,
        ];
    }

    /**
     * Categories for the filter (all) and the form (active only, plus the chosen one).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return ExpenseCategory::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->displayName()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function formCategoryOptions(): array
    {
        return ExpenseCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->displayName()])->all();
    }

    /**
     * Active sub-categories of the category chosen in the form.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function subCategoryOptions(): array
    {
        $categoryId = (int) ($this->form['expense_category_id'] ?? 0);
        if ($categoryId === 0) {
            return [];
        }

        return ExpenseSubCategory::query()->where('expense_category_id', $categoryId)->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (ExpenseSubCategory $s) => [$s->id => $s->displayName()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return TreasuryAccount::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $a->displayName()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function formAccountOptions(): array
    {
        return TreasuryAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (TreasuryAccount $a) => [$a->id => $a->displayName()])->all();
    }

    /** Current balance of the account chosen in the form. */
    #[Computed]
    public function formAccountBalance(): ?Money
    {
        $account = ctype_digit((string) ($this->form['account_id'] ?? '')) ? TreasuryAccount::query()->with('ledgerAccount')->find((int) $this->form['account_id']) : null;

        return $account !== null ? app(LedgerQueries::class)->balance($account->ledgerAccount) : null;
    }

    public function create(): void
    {
        $this->authorize(Permission::ExpensesRecord->value);
        $this->resetErrorBag();
        $this->form = [
            'account_id' => (string) (array_key_first($this->formAccountOptions) ?? ''),
            'entry_date' => now()->format('Y-m-d'),
            'expense_category_id' => ctype_digit($this->category) && isset($this->formCategoryOptions[(int) $this->category]) ? $this->category : '',
            'expense_sub_category_id' => '',
            'amount' => '',
            'description' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showForm = true;
    }

    /** Save; with $another the form stays open for the next expense (same date, account and category). */
    public function save(bool $another = false): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $expense = $this->runAction(fn () => app(RecordExpense::class)->handle($actor, $this->form, $this->formKey), 'form');

        $this->toast(__('Expense :number recorded.', ['number' => $expense->entry->number]));
        unset($this->expenses, $this->totals, $this->formAccountBalance);

        if ($another) {
            $this->form['amount'] = '';
            $this->form['description'] = '';
            $this->formKey = (string) Str::uuid();
            $this->dispatch('expense-saved');

            return;
        }

        $this->showForm = false;
    }

    public function confirmReverse(int $id): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $expense = Expense::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->reversingId = $expense->id;
        $this->reverseForm = ['reason' => ''];
        $this->formKey = (string) Str::uuid();
        $this->showReverseForm = true;
    }

    public function reverse(ReverseEntry $reverseEntry): void
    {
        $expense = Expense::query()->with('entry')->findOrFail((int) $this->reversingId);

        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $expense->entry, $this->reverseForm, $this->formKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed by :reversal.', ['number' => $expense->entry->number, 'reversal' => $reversal->number]));
        unset($this->expenses, $this->totals);
    }

    #[Computed]
    public function reversing(): ?Expense
    {
        return $this->reversingId ? Expense::query()->with('entry')->find($this->reversingId) : null;
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        return view('livewire.expenses.expense-index', [
            'canRecord' => $actor->can(Permission::ExpensesRecord->value),
            'canReverse' => $actor->can(Permission::TransactionsReverse->value),
            'range' => $this->dateRange(),
        ])->title(__('Expenses'));
    }

    /**
     * Expenses matching the filters, or null while the date range is invalid.
     *
     * @return Builder<Expense>|null
     */
    private function filtered(): ?Builder
    {
        $range = $this->dateRange();
        if ($range === null) {
            return null;
        }

        $search = trim($this->search);

        return Expense::query()
            ->whereBetween('expense_date', [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')])
            ->when(ctype_digit($this->category), fn (Builder $q) => $q->where('expense_category_id', (int) $this->category))
            ->when(ctype_digit($this->account), fn (Builder $q) => $q->where('treasury_account_id', (int) $this->account))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhereHas('entry', fn (Builder $e) => $e->where('number', 'like', "%{$search}%"))
                ->orWhereHas('subCategory', fn (Builder $s) => $s->where('name', 'like', "%{$search}%")->orWhere('name_ur', 'like', "%{$search}%"))));
    }
}
