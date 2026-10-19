<?php

use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Settings\SaveExpenseCategory;
use App\Actions\Settings\SetArchived;
use App\Enums\AccountType;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Livewire\Accounts\CashBook;
use App\Livewire\Expenses\ExpenseIndex;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubCategory;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->cash = makeTreasuryAccount($this->owner, 'Cash', '20000');
    $this->fuel = ExpenseCategory::query()->where('name', 'Fuel and vehicles')->sole();
    $this->diesel = ExpenseSubCategory::query()->where('name', 'Diesel')->sole();
});

/** The field names of a validation failure. */
function expenseErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    test()->fail('Expected a validation error.');
}

describe('recording expenses', function () {
    it('pays an expense from cash into the category\'s own expense account', function () {
        $expense = recordExpense($this->owner, $this->cash, $this->fuel, '3,500', [
            'expense_sub_category_id' => (string) $this->diesel->id,
            'description' => 'Generator diesel, 12 litres',
        ]);

        $entry = $expense->entry;
        $account = LedgerAccount::query()->findOrFail($this->fuel->fresh()->ledger_account_id);

        expect(balanceOf($this->cash))->toBe('16500.00')
            ->and($entry->type)->toBe(JournalType::Expense)
            ->and($entry->number)->toBe('EX-'.now()->year.'-0001')
            ->and($entry->memo)->toBe('Generator diesel, 12 litres')
            ->and($account->code)->toBe('5001')
            ->and($account->type)->toBe(AccountType::Expense)
            ->and($account->name)->toBe('Fuel and vehicles')
            ->and($account->name_ur)->toBe($this->fuel->name_ur)
            ->and(app(LedgerQueries::class)->balance($account)->toDecimal())->toBe('3500.00')
            ->and($expense->amount->toDecimal())->toBe('3500.00')
            ->and($expense->expense_sub_category_id)->toBe($this->diesel->id)
            ->and($expense->created_by)->toBe($this->owner->id)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('reuses a category\'s account and gives the next category the next code', function () {
        recordExpense($this->owner, $this->cash, $this->fuel, '100');
        recordExpense($this->owner, $this->cash, $this->fuel, '200');
        $repairs = ExpenseCategory::query()->where('name', 'Repairs and maintenance')->sole();
        recordExpense($this->owner, $this->cash, $repairs, '300');

        // Category accounts only (not system expense accounts such as Material shortages).
        $codes = LedgerAccount::query()->where('type', AccountType::Expense)->whereNull('system_key')->orderBy('code')->pluck('code')->all();

        expect($codes)->toBe(['5001', '5002'])
            ->and(app(LedgerQueries::class)->balance(LedgerAccount::query()->findOrFail($this->fuel->fresh()->ledger_account_id))->toDecimal())->toBe('300.00');
    });

    it('shows the expense on the trial balance, which stays balanced', function () {
        recordExpense($this->owner, $this->cash, $this->fuel, '1250.50');

        $trial = app(LedgerQueries::class)->trialBalance(now());
        $row = $trial['rows']->first(fn ($row) => $row['account']->code === '5001');

        expect($row['debit']->toDecimal())->toBe('1250.50')
            ->and($trial['total_debit']->equals($trial['total_credit']))->toBeTrue();
    });

    it('renames the expense account with its category', function () {
        recordExpense($this->owner, $this->cash, $this->fuel, '100');

        app(SaveExpenseCategory::class)->handle($this->owner, $this->fuel->fresh(), ['name' => 'Fuel', 'name_ur' => 'ایندھن', 'sort_order' => '10']);

        $account = LedgerAccount::query()->findOrFail($this->fuel->fresh()->ledger_account_id);
        expect($account->name)->toBe('Fuel')->and($account->name_ur)->toBe('ایندھن');
    });

    it('refuses to spend more than the account holds and leaves nothing behind', function () {
        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $this->fuel, '20000.01')))->toBe(['amount'])
            ->and(Expense::query()->count())->toBe(0)
            ->and(JournalEntry::query()->where('type', JournalType::Expense)->count())->toBe(0)
            // The category's expense account is created with the first expense, so it was rolled back too.
            ->and($this->fuel->fresh()->ledger_account_id)->toBeNull();
    });

    it('returns the first expense when the same form is sent twice', function () {
        $key = (string) Str::uuid();

        $first = recordExpense($this->owner, $this->cash, $this->fuel, '500', key: $key);
        $second = recordExpense($this->owner, $this->cash, $this->fuel, '500', key: $key);

        expect($second->id)->toBe($first->id)
            ->and(Expense::query()->count())->toBe(1)
            ->and(balanceOf($this->cash))->toBe('19500.00');
    });

    it('is corrected by reversing its entry, never edited or deleted', function () {
        $expense = recordExpense($this->owner, $this->cash, $this->fuel, '900');

        expect(fn () => $expense->update(['amount' => Money::fromRupees('90')]))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $expense->delete())->toThrow(LedgerIntegrityException::class);

        app(ReverseEntry::class)->handle($this->owner, $expense->entry, ['reason' => 'Wrong amount'], (string) Str::uuid());

        expect($expense->fresh()->isReversed())->toBeTrue()
            ->and(balanceOf($this->cash))->toBe('20000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('is refused for roles without permission, even when calling the action directly', function (Role $role) {
        recordExpense(makeUser($this->kiln, $role), $this->cash, $this->fuel, '10');
    })->throws(AuthorizationException::class)->with([Role::Viewer, Role::DataEntry]);

    it('lets the munshi record expenses', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);

        expect(recordExpense($munshi, $this->cash, $this->fuel, '10')->created_by)->toBe($munshi->id);
    });
});

describe('validation', function () {
    it('checks every field', function (array $input, string $field) {
        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $this->fuel, '100', $input)))->toContain($field);
    })->with([
        'no amount' => [['amount' => ''], 'amount'],
        'zero amount' => [['amount' => '0'], 'amount'],
        'text amount' => [['amount' => 'abc'], 'amount'],
        'no category' => [['expense_category_id' => ''], 'expense_category_id'],
        'unknown category' => [['expense_category_id' => '999999'], 'expense_category_id'],
        'no account' => [['account_id' => ''], 'account_id'],
        'future date' => [fn () => ['entry_date' => now()->addDay()->format('Y-m-d')], 'entry_date'],
        'before the account opened' => [fn () => ['entry_date' => now()->subDays(31)->format('Y-m-d')], 'entry_date'],
        'long details' => [['description' => str_repeat('x', 256)], 'description'],
    ]);

    it('accepts only a sub-category of the chosen category', function () {
        $electricity = ExpenseSubCategory::query()->where('name', 'Electricity')->sole();

        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $this->fuel, '100', ['expense_sub_category_id' => (string) $electricity->id])))
            ->toBe(['expense_sub_category_id']);
    });

    it('refuses archived categories and sub-categories', function () {
        app(SetArchived::class)->handle($this->owner, $this->diesel, true);
        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $this->fuel, '100', ['expense_sub_category_id' => (string) $this->diesel->id])))
            ->toBe(['expense_sub_category_id']);

        app(SetArchived::class)->handle($this->owner, $this->fuel, true);
        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $this->fuel, '100')))->toBe(['expense_category_id']);
    });

    it('refuses a category of another kiln', function () {
        $other = makeSeededKiln();
        $foreign = app(CurrentKiln::class)->run($other, fn () => ExpenseCategory::query()->where('name', 'Fuel and vehicles')->sole());
        app(CurrentKiln::class)->set($this->kiln);

        expect(expenseErrors(fn () => recordExpense($this->owner, $this->cash, $foreign, '100')))->toBe(['expense_category_id']);
    });
});

describe('verifier', function () {
    it('catches an expense that no longer matches its entry, and an expense entry without its expense', function () {
        $expense = recordExpense($this->owner, $this->cash, $this->fuel, '700');
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('expenses')->where('id', $expense->id)->update(['amount' => 70000 + 1]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain("Expense #{$expense->id}");

        DB::table('expenses')->where('id', $expense->id)->delete();
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('an expense entry without its expense record');
    });
});

describe('screens', function () {
    it('opens for the right roles only', function (Role $role, int $status) {
        $this->actingAs(makeUser($this->kiln, $role))->get('/expenses')->assertStatus($status);
    })->with([
        'owner' => [Role::Owner, 200],
        'manager' => [Role::Manager, 200],
        'munshi' => [Role::Munshi, 200],
        'viewer' => [Role::Viewer, 403],
        'data entry' => [Role::DataEntry, 403],
    ]);

    it('records an expense from the form, and keeps the form open for the next one', function () {
        Livewire::test(ExpenseIndex::class)
            ->call('create')
            ->set('form.expense_category_id', (string) $this->fuel->id)
            ->assertSee('Diesel')
            ->set('form.expense_sub_category_id', (string) $this->diesel->id)
            ->set('form.amount', '1,200')
            ->set('form.description', 'Tractor diesel')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertSet('showForm', true)
            ->assertSet('form.amount', '')
            ->assertSet('form.expense_category_id', (string) $this->fuel->id)
            ->set('form.amount', '300')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertSee('Tractor diesel');

        expect(Expense::query()->count())->toBe(2)->and(balanceOf($this->cash))->toBe('18500.00');
    });

    it('clears the sub-category when the category changes', function () {
        Livewire::test(ExpenseIndex::class)
            ->call('create')
            ->set('form.expense_category_id', (string) $this->fuel->id)
            ->set('form.expense_sub_category_id', (string) $this->diesel->id)
            ->set('form.expense_category_id', (string) ExpenseCategory::query()->where('name', 'Utility bills')->sole()->id)
            ->assertSet('form.expense_sub_category_id', '');
    });

    it('shows validation errors on the form fields', function () {
        Livewire::test(ExpenseIndex::class)
            ->call('create')
            ->set('form.amount', '0')
            ->call('save')
            ->assertHasErrors(['form.amount', 'form.expense_category_id']);
    });

    it('totals the period by category, leaving out reversed expenses', function () {
        $repairs = ExpenseCategory::query()->where('name', 'Repairs and maintenance')->sole();
        recordExpense($this->owner, $this->cash, $this->fuel, '1000');
        recordExpense($this->owner, $this->cash, $repairs, '2500');
        $wrong = recordExpense($this->owner, $this->cash, $repairs, '4000');
        app(ReverseEntry::class)->handle($this->owner, $wrong->entry, ['reason' => 'Entered twice'], (string) Str::uuid());

        $component = Livewire::test(ExpenseIndex::class);
        $totals = $component->instance()->totals;

        expect($totals['total']->toDecimal())->toBe('3500.00')
            ->and($totals['count'])->toBe(2)
            ->and(collect($totals['by_category'])->map(fn ($row) => [$row['name'], $row['amount']->toDecimal()])->all())
            ->toBe([['Repairs and maintenance', '2500.00'], ['Fuel and vehicles', '1000.00']]);

        $component->assertSee('Reversed');
    });

    it('filters by category, account and period', function () {
        $bank = makeTreasuryAccount($this->owner, 'Bank', '5000', TreasuryKind::Bank);
        $repairs = ExpenseCategory::query()->where('name', 'Repairs and maintenance')->sole();
        recordExpense($this->owner, $this->cash, $this->fuel, '100', ['description' => 'Fuel from cash']);
        recordExpense($this->owner, $bank, $repairs, '200', ['description' => 'Repair from bank']);
        recordExpense($this->owner, $this->cash, $this->fuel, '300', ['description' => 'Old fuel', 'entry_date' => now()->subDays(20)->format('Y-m-d')]);

        Livewire::test(ExpenseIndex::class)
            ->set('from', now()->subDays(5)->format('Y-m-d'))
            ->assertSee('Fuel from cash')->assertSee('Repair from bank')->assertDontSee('Old fuel')
            ->set('category', (string) $repairs->id)->assertSee('Repair from bank')->assertDontSee('Fuel from cash')
            ->set('category', '')->set('account', (string) $this->cash->id)->assertSee('Fuel from cash')->assertDontSee('Repair from bank')
            ->set('from', now()->format('Y-m-d'))->set('to', now()->subDay()->format('Y-m-d'))->assertSee('Choose a valid date range');
    });

    it('reverses an expense from the list (owner and manager only)', function () {
        $expense = recordExpense($this->owner, $this->cash, $this->fuel, '600');

        Livewire::test(ExpenseIndex::class)
            ->call('confirmReverse', $expense->id)
            ->set('reverseForm.reason', 'Wrong category')
            ->call('reverse')
            ->assertHasNoErrors();

        expect($expense->fresh()->isReversed())->toBeTrue();

        actingInKiln(makeUser($this->kiln, Role::Munshi), $this->kiln);
        Livewire::test(ExpenseIndex::class)->call('confirmReverse', $expense->id)->assertForbidden();
    });

    it('describes the expense by its category in the cash book', function () {
        recordExpense($this->owner, $this->cash, $this->fuel, '450', ['description' => 'Diesel for generator']);

        Livewire::test(CashBook::class, ['account' => $this->cash])
            ->assertSee('Fuel and vehicles: Diesel for generator');
    });
});
