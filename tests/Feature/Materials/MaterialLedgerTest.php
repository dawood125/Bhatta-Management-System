<?php

use App\Accounting\LedgerIntegrityException;
use App\Accounting\LedgerQueries;
use App\Accounting\LedgerVerifier;
use App\Actions\Accounting\ReverseEntry;
use App\Actions\Materials\RecordMaterialPurchase;
use App\Actions\Materials\ReverseMaterialEntry;
use App\Actions\Settings\SaveMaterial;
use App\Actions\Settings\SaveUnit;
use App\Actions\Settings\SetArchived;
use App\Enums\AccountType;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\TreasuryKind;
use App\Materials\MaterialStock;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\MaterialAdjustment;
use App\Models\MaterialMovement;
use App\Models\MaterialPurchase;
use App\Models\MaterialPurchaseCharge;
use App\Models\Unit;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->supplier = makeParty($this->owner, 'Soil Supplier', [PartyRole::Supplier]);
    $this->soil = material('soil');
    $this->coal = material('coal');
});

/** The field names of a validation failure. */
function materialErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    test()->fail('Expected a validation error.');
}

function ledgerBalance(string $systemKey): string
{
    return app(LedgerQueries::class)->balance(LedgerAccount::bySystemKey($systemKey))->toDecimal();
}

describe('acceptance example (plan M5)', function () {
    it('buys 1,000 units at 50 with a 5,000 trolley charge, uses 400, and never goes negative', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '1,000', '50']], [['transport', '5,000']]);

        expect(stockOf($this->soil))->toBe(['1000.000', '55000.00'])
            ->and($purchase->total->toDecimal())->toBe('55000.00')
            ->and($purchase->entry->number)->toBe('PU-'.now()->year.'-0001')
            ->and($this->supplier->balance()->toDecimal())->toBe('-55000.00') // we owe the supplier goods + trolley
            ->and(ledgerBalance(LedgerAccount::MATERIAL_STOCK))->toBe('55000.00');

        $usage = recordUsage($this->owner, [[$this->soil, '400']]);

        expect(stockOf($this->soil))->toBe(['600.000', '33000.00'])
            ->and($usage->total_cost->toDecimal())->toBe('22000.00')
            ->and(app(LedgerQueries::class)->balance(LedgerAccount::query()->findOrFail($this->soil->fresh()->usage_account_id))->toDecimal())->toBe('22000.00')
            ->and(ledgerBalance(LedgerAccount::MATERIAL_STOCK))->toBe('33000.00');

        expect(materialErrors(fn () => recordUsage($this->owner, [[$this->soil, '600.01']])))->toBe(['lines.0.quantity'])
            ->and(stockOf($this->soil))->toBe(['600.000', '33000.00'])
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('purchases', function () {
    beforeEach(function () {
        $this->cash = makeTreasuryAccount($this->owner, 'Cash', '100000');
        $this->driver = makeParty($this->owner, 'Trolley Driver', [PartyRole::Driver]);
    });

    it('sends each charge to whoever is owed or paid', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '1000', '50']], [
            ['transport', '5000', MaterialPurchaseCharge::TO_PARTY, $this->driver, 'Trolley, 2 trips'],
            ['excavator', '3000', MaterialPurchaseCharge::FROM_ACCOUNT, $this->cash],
            ['loading', '500', MaterialPurchaseCharge::WITH_GOODS],
        ]);

        expect(stockOf($this->soil))->toBe(['1000.000', '58500.00'])
            ->and($this->supplier->balance()->toDecimal())->toBe('-50500.00')
            ->and($this->driver->balance()->toDecimal())->toBe('-5000.00')
            ->and(balanceOf($this->cash))->toBe('97000.00')
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('pays now from an account, with an optional supplier on record', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->coal, '1.5', '40000']], [['transport', '1000']], ['payment' => 'paid', 'account_id' => (string) $this->cash->id]);

        expect(stockOf($this->coal))->toBe(['1.500', '61000.00'])
            ->and(balanceOf($this->cash))->toBe('39000.00')
            ->and($this->supplier->balance()->isZero())->toBeTrue()
            ->and($purchase->supplier_id)->toBe($this->supplier->id)
            ->and($purchase->treasury_account_id)->toBe($this->cash->id);
    });

    it('refuses to pay more than the account holds and records nothing', function () {
        expect(materialErrors(fn () => recordPurchase($this->owner, null, [[$this->coal, '3', '40000']], [], ['payment' => 'paid', 'account_id' => (string) $this->cash->id])))
            ->toBe(['account_id'])
            ->and(stockOf($this->coal))->toBe(['0.000', '0.00'])
            ->and(MaterialPurchase::query()->count())->toBe(0);
    });

    it('splits charges over the lines by value, to the exact paisa', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10'], [$this->coal, '1', '2000']], [['transport', '100.01']]);

        $lines = $purchase->lines;
        expect($lines[0]->charges->toDecimal())->toBe('33.34') // 1,000 of 3,000
            ->and($lines[1]->charges->toDecimal())->toBe('66.67') // 2,000 of 3,000
            ->and($lines[0]->charges->add($lines[1]->charges)->toDecimal())->toBe('100.01')
            ->and(stockOf($this->soil)[1])->toBe('1033.34')
            ->and(stockOf($this->coal)[1])->toBe('2066.67');
    });

    it('allocates any charge total exactly', function (string $charges, array $weights) {
        $shares = app(RecordMaterialPurchase::class)->allocate(Money::fromRupees($charges), array_map(fn ($w) => Money::fromRupees($w), $weights));

        expect(Money::sum($shares)->toDecimal())->toBe(Money::fromRupees($charges)->toDecimal());
    })->with([
        ['0.03', ['1', '1', '1', '1']],
        ['10', ['3', '3', '3']],
        ['99999.99', ['0.01', '123456.78', '5']],
        ['0', ['10', '20']],
    ]);

    it('checks every field', function (array $input, string $field) {
        $base = [
            'purchase_date' => now()->format('Y-m-d'), 'payment' => 'credit', 'supplier_id' => (string) $this->supplier->id, 'account_id' => '',
            'reference' => '', 'notes' => '',
            'lines' => [['material_id' => (string) $this->soil->id, 'quantity' => '10', 'rate' => '50']],
            'charges' => [],
        ];

        expect(materialErrors(fn () => app(RecordMaterialPurchase::class)->handle($this->owner, array_replace_recursive($base, $input), (string) Str::uuid())))->toContain($field);
    })->with([
        'no supplier on credit' => [['supplier_id' => ''], 'supplier_id'],
        'no account when paid' => [['payment' => 'paid'], 'account_id'],
        'unknown payment' => [['payment' => 'barter'], 'payment'],
        'future date' => [fn () => ['purchase_date' => now()->addDay()->format('Y-m-d')], 'purchase_date'],
        'no material' => [['lines' => [['material_id' => '']]], 'lines.0.material_id'],
        'zero quantity' => [['lines' => [['quantity' => '0']]], 'lines.0.quantity'],
        'text quantity' => [['lines' => [['quantity' => 'a lot']]], 'lines.0.quantity'],
        'too many decimals for cft' => [['lines' => [['quantity' => '10.555']]], 'lines.0.quantity'],
        'zero rate' => [['lines' => [['rate' => '0']]], 'lines.0.rate'],
        'charge without amount' => [['charges' => [['kind' => 'transport', 'amount' => '', 'payee' => 'with_goods']]], 'charges.0.amount'],
        'charge to nobody' => [['charges' => [['kind' => 'transport', 'amount' => '10', 'payee' => 'party', 'party_id' => '']]], 'charges.0.party_id'],
        'charge kind unknown' => [['charges' => [['kind' => 'bribe', 'amount' => '10', 'payee' => 'with_goods']]], 'charges.0.kind'],
    ]);

    it('accepts each material only once and at most 20 lines', function () {
        expect(materialErrors(fn () => recordPurchase($this->owner, $this->supplier, [[$this->soil, '1', '1'], [$this->soil, '2', '1']])))->toContain('lines.1.material_id');
    });

    it('counts each material in its own unit\'s decimals', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->coal, '12.345', '40000']]);
        expect(stockOf($this->coal)[0])->toBe('12.345');

        expect(materialErrors(fn () => recordPurchase($this->owner, $this->supplier, [[material('bora'), '1.5', '300']])))->toBe(['lines.0.quantity']);
    });

    it('refuses archived materials and another kiln\'s material', function () {
        $other = makeSeededKiln();
        $foreign = app(CurrentKiln::class)->run($other, fn () => Material::query()->where('code', 'soil')->sole());
        app(CurrentKiln::class)->set($this->kiln);
        app(SetArchived::class)->handle($this->owner, material('tuka'), true);

        expect(materialErrors(fn () => recordPurchase($this->owner, $this->supplier, [[$foreign, '1', '1']])))->toBe(['lines.0.material_id'])
            ->and(materialErrors(fn () => recordPurchase($this->owner, $this->supplier, [[material('tuka'), '1', '1']])))->toBe(['lines.0.material_id']);
    });

    it('returns the first purchase when the same form is sent twice', function () {
        $key = (string) Str::uuid();
        $first = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']], key: $key);
        $second = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']], key: $key);

        expect($second->id)->toBe($first->id)->and(stockOf($this->soil)[0])->toBe('10.000');
    });
});

describe('average cost', function () {
    it('values usage at the weighted average cost of the stock', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10']]);
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '20']]);

        $usage = recordUsage($this->owner, [[$this->soil, '50']]);

        expect($usage->total_cost->toDecimal())->toBe('750.00')
            ->and(stockOf($this->soil))->toBe(['150.000', '2250.00']);
    });

    it('takes out all of the value with the last of the stock, leaving no rounding remainder', function () {
        recordPurchase($this->owner, $this->supplier, [[material('bora'), '3', '33.33']]); // 99.99 for 3 bags

        $costs = [];
        foreach (range(1, 3) as $i) {
            $costs[] = recordUsage($this->owner, [[material('bora'), '1']])->total_cost->toDecimal();
        }

        expect($costs)->toBe(['33.33', '33.33', '33.33'])->and(stockOf(material('bora')))->toBe(['0.000', '0.00']);
    });

    it('records several materials in one entry, each in its own expense account', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->coal, '10', '40000'], [material('bora'), '100', '300']]);

        $usage = recordUsage($this->owner, [[$this->coal, '2.5', 'Firing line 3'], [material('bora'), '20']], ['notes' => 'Night shift']);

        $codes = LedgerAccount::query()->where('type', AccountType::Expense)->whereBetween('code', ['5501', '5899'])->orderBy('code')->pluck('name', 'code')->all();

        expect($usage->total_cost->toDecimal())->toBe('106000.00') // 2.5 × 40,000 + 20 × 300
            ->and($codes)->toBe(['5501' => 'Coal (Kola) used', '5502' => 'Bora used'])
            ->and($usage->movements->firstWhere('material_id', $this->coal->id)->memo)->toBe('Firing line 3')
            ->and($usage->entry->number)->toBe('MU-'.now()->year.'-0001');
    });
});

describe('stock never goes negative', function () {
    it('refuses usage dated before the stock arrived', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10']]);

        expect(materialErrors(fn () => recordUsage($this->owner, [[$this->soil, '10']], ['usage_date' => now()->subDay()->format('Y-m-d')])))->toBe(['lines.0.quantity']);
    });

    it('refuses a back-dated usage that would make a later day negative', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10']], extra: ['purchase_date' => now()->subDays(5)->format('Y-m-d')]);
        recordUsage($this->owner, [[$this->soil, '80']], ['usage_date' => now()->subDays(1)->format('Y-m-d')]);

        // On day -3 there were 100, but taking 30 then would leave -10 after day -1.
        expect(materialErrors(fn () => recordUsage($this->owner, [[$this->soil, '30']], ['usage_date' => now()->subDays(3)->format('Y-m-d')])))->toBe(['lines.0.quantity']);

        recordUsage($this->owner, [[$this->soil, '20']], ['usage_date' => now()->subDays(3)->format('Y-m-d')]);
        expect(stockOf($this->soil)[0])->toBe('0.000');
    });
});

describe('opening stock and shortages', function () {
    it('enters opening stock against opening balances', function () {
        $opening = recordOpeningStock($this->owner, $this->coal, '20', '800000');

        expect(stockOf($this->coal))->toBe(['20.000', '800000.00'])
            ->and(ledgerBalance(LedgerAccount::OPENING_BALANCE_EQUITY))->toBe('800000.00')
            ->and($opening->entry->number)->toBe('SA-'.now()->year.'-0001');
    });

    it('needs the value of opening stock, and a reason for a shortage', function () {
        expect(materialErrors(fn () => recordOpeningStock($this->owner, $this->coal, '20', '')))->toBe(['value'])
            ->and(materialErrors(fn () => recordShortage($this->owner, $this->coal, '1', '')))->toBe(['reason']);
    });

    it('records a shortage at average cost and never below zero', function () {
        recordOpeningStock($this->owner, $this->coal, '10', '400000');

        $shortage = recordShortage($this->owner, $this->coal, '0.5', 'Rain damage');

        expect($shortage->value->toDecimal())->toBe('20000.00')
            ->and(stockOf($this->coal))->toBe(['9.500', '380000.00'])
            ->and(ledgerBalance(LedgerAccount::MATERIAL_SHORTAGES))->toBe('20000.00')
            ->and(materialErrors(fn () => recordShortage($this->owner, $this->coal, '9.501')))->toBe(['quantity']);
    });

    it('is only for the owner and manager', function () {
        recordOpeningStock(makeUser($this->kiln, Role::Munshi), $this->coal, '1', '100');
    })->throws(AuthorizationException::class);
});

describe('reversals', function () {
    it('reverses a purchase, returning the stock and the supplier\'s balance', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '1000', '50']], [['transport', '5000']]);

        $reversal = app(ReverseMaterialEntry::class)->handle($this->owner, $purchase->entry, ['reason' => 'Wrong supplier'], (string) Str::uuid());

        expect($purchase->entry->fresh()->isReversed())->toBeTrue()
            ->and($reversal->type)->toBe(JournalType::Reversal)
            ->and(stockOf($this->soil))->toBe(['0.000', '0.00'])
            ->and($this->supplier->balance()->isZero())->toBeTrue()
            ->and(MaterialMovement::query()->whereNotNull('reversal_of_id')->count())->toBe(1)
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('refuses to reverse a purchase whose stock has been used since', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '50']]);
        recordUsage($this->owner, [[$this->soil, '60']]);

        expect(materialErrors(fn () => app(ReverseMaterialEntry::class)->handle($this->owner, $purchase->entry, ['reason' => 'Mistake made'], (string) Str::uuid())))->toBe(['reason'])
            ->and($purchase->entry->fresh()->isReversed())->toBeFalse();
    });

    it('refuses a reversal that would leave stock without a sensible value', function () {
        $cheap = recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '10']]);
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '100', '30']]);
        recordUsage($this->owner, [[$this->soil, '100']]); // takes 2,000 at the average of 20

        // Reversing the 3,000 purchase would leave 0 units worth -1,000; the 1,000 one leaves 0 worth 1,000.
        expect(materialErrors(fn () => app(ReverseMaterialEntry::class)->handle($this->owner, $cheap->entry, ['reason' => 'Mistake made'], (string) Str::uuid())))->toBe(['reason']);
    });

    it('reverses usage and opening stock', function () {
        recordOpeningStock($this->owner, $this->coal, '10', '400000');
        $usage = recordUsage($this->owner, [[$this->coal, '4']]);

        app(ReverseMaterialEntry::class)->handle($this->owner, $usage->entry, ['reason' => 'Entered twice'], (string) Str::uuid());
        expect(stockOf($this->coal))->toBe(['10.000', '400000.00']);

        $opening = MaterialAdjustment::query()->sole();
        app(ReverseMaterialEntry::class)->handle($this->owner, $opening->entry, ['reason' => 'Counted wrongly'], (string) Str::uuid());
        expect(stockOf($this->coal))->toBe(['0.000', '0.00'])->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });

    it('is refused from the general transactions screen, and for the munshi', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);

        expect(fn () => app(ReverseEntry::class)->handle($this->owner, $purchase->entry, ['reason' => 'Mistake made'], (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => app(ReverseMaterialEntry::class)->handle(makeUser($this->kiln, Role::Munshi), $purchase->entry, ['reason' => 'Mistake made'], (string) Str::uuid()))->toThrow(AuthorizationException::class);
    });

    it('returns the same reversal when the dialog is sent twice', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        $key = (string) Str::uuid();

        $first = app(ReverseMaterialEntry::class)->handle($this->owner, $purchase->entry, ['reason' => 'Mistake made'], $key);
        $second = app(ReverseMaterialEntry::class)->handle($this->owner, $purchase->entry->fresh(), ['reason' => 'Mistake made'], $key);

        expect($second->id)->toBe($first->id)->and(MaterialMovement::query()->whereNotNull('reversal_of_id')->count())->toBe(1);
    });
});

describe('materials and units', function () {
    it('keeps a material\'s unit once it has stock, and renames its usage account with it', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        recordUsage($this->owner, [[$this->soil, '1']]);
        $ton = Unit::query()->where('code', 'ton')->sole();

        expect(materialErrors(fn () => app(SaveMaterial::class)->handle($this->owner, $this->soil->fresh(), ['name' => 'Soil', 'name_ur' => 'مٹی', 'unit_id' => (string) $ton->id, 'kind' => 'raw_material', 'sort_order' => '10'])))->toBe(['unit_id']);

        app(SaveMaterial::class)->handle($this->owner, $this->soil->fresh(), ['name' => 'Clay', 'name_ur' => 'چکنی مٹی', 'unit_id' => (string) $this->soil->unit_id, 'kind' => 'raw_material', 'sort_order' => '10']);

        $account = LedgerAccount::query()->findOrFail($this->soil->fresh()->usage_account_id);
        expect($account->name)->toBe('Clay used')->and($account->name_ur)->toBe('چکنی مٹی (استعمال)');
    });

    it('does not let a unit lose decimal places once materials in it have stock', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->coal, '1.5', '40000']]);
        $ton = Unit::query()->where('code', 'ton')->sole();

        app(SaveUnit::class)->handle($this->owner, $ton, ['name' => 'Ton', 'name_ur' => 'ٹن', 'symbol' => 't', 'decimals' => '3']);
        expect(materialErrors(fn () => app(SaveUnit::class)->handle($this->owner, $ton->fresh(), ['name' => 'Ton', 'name_ur' => 'ٹن', 'symbol' => 't', 'decimals' => '2'])))->toBe(['decimals']);
    });

    it('archives a material only when its stock is empty', function () {
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);

        expect(materialErrors(fn () => app(SetArchived::class)->handle($this->owner, $this->soil, true)))->not->toBe([]);

        recordUsage($this->owner, [[$this->soil, '10']]);
        app(SetArchived::class)->handle($this->owner, $this->soil->fresh(), true);
        expect($this->soil->fresh()->is_active)->toBeFalse();
    });

    it('gives expense categories and materials separate account codes', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '1000');
        recordExpense($this->owner, $cash, ExpenseCategory::query()->firstOrFail(), '10');
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        recordUsage($this->owner, [[$this->soil, '1']]);

        expect(LedgerAccount::query()->where('type', AccountType::Expense)->whereNull('system_key')->orderBy('code')->pluck('code')->all())->toBe(['5001', '5501']);
    });
});

describe('permissions and integrity', function () {
    it('is refused for roles without permission, even when calling the actions directly', function (Role $role) {
        recordPurchase(makeUser($this->kiln, $role), $this->supplier, [[$this->soil, '1', '1']]);
    })->throws(AuthorizationException::class)->with([Role::Viewer, Role::DataEntry]);

    it('lets the munshi buy and record usage', function () {
        $munshi = makeUser($this->kiln, Role::Munshi);
        recordPurchase($munshi, $this->supplier, [[$this->soil, '10', '50']]);
        recordUsage($munshi, [[$this->soil, '5']]);

        expect(stockOf($this->soil)[0])->toBe('5.000');
    });

    it('never edits or deletes stock movements and purchases', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        $movement = MaterialMovement::query()->sole();

        expect(fn () => $movement->update(['memo' => 'x']))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $movement->delete())->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $purchase->update(['notes' => 'x']))->toThrow(LedgerIntegrityException::class)
            ->and(fn () => $purchase->lines->first()->delete())->toThrow(LedgerIntegrityException::class);
    });

    it('lets the verifier catch stock that no longer matches the books', function () {
        $purchase = recordPurchase($this->owner, $this->supplier, [[$this->soil, '10', '50']]);
        recordUsage($this->owner, [[$this->soil, '4']]);
        expect(app(LedgerVerifier::class)->verify())->toBe([]);

        DB::table('material_movements')->where('type', 'usage')->update(['value_out' => 1]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain('do not match the Material stock account');

        DB::table('material_movements')->where('type', 'usage')->update(['value_out' => 20000]);
        DB::table('material_purchases')->where('id', $purchase->id)->update(['goods_total' => 1]);
        expect(collect(app(LedgerVerifier::class)->verify())->implode(' '))->toContain("Purchase {$purchase->entry->number}");
    });

    it('keeps the trial balance balanced and the stock account equal to the stock value', function () {
        $cash = makeTreasuryAccount($this->owner, 'Cash', '500000', TreasuryKind::Cash);
        recordOpeningStock($this->owner, $this->coal, '5', '200000');
        recordPurchase($this->owner, $this->supplier, [[$this->soil, '1000', '50'], [$this->coal, '2', '41000']], [['transport', '3000', MaterialPurchaseCharge::FROM_ACCOUNT, $cash]]);
        recordUsage($this->owner, [[$this->soil, '250'], [$this->coal, '3']]);
        recordShortage($this->owner, $this->coal, '0.25');

        $trial = app(LedgerQueries::class)->trialBalance(now());
        $stockValue = Money::sum(array_map(fn ($m) => app(MaterialStock::class)->levels([$m->id])[$m->id]['value'], [$this->soil, $this->coal]));

        expect($trial['total_debit']->equals($trial['total_credit']))->toBeTrue()
            ->and(ledgerBalance(LedgerAccount::MATERIAL_STOCK))->toBe($stockValue->toDecimal())
            ->and(app(LedgerVerifier::class)->verify())->toBe([]);
    });
});
