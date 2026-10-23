<?php

namespace App\Accounting;

use App\Enums\AccountType;
use App\Enums\TreasuryKind;
use App\Models\ExpenseCategory;
use App\Models\LabourCategory;
use App\Models\LedgerAccount;
use App\Models\Material;

/**
 * Creates the ledger accounts the system needs. Each module adds its own system accounts when
 * it is built (receivables, payables, sales, ...), so nothing unused is created early.
 *
 * Code ranges: 1xxx assets (1001-1099 cash/bank, 1100 receivables, 1200 material stock),
 * 2xxx liabilities (2001-2099 credit cards, 2100 payables, 2200 wages payable), 3xxx equity,
 * 4900 deductions from workers, 5001-5499 expense categories, 5501-5899 materials used,
 * 5900 material shortages, 6001-6499 labour categories.
 */
class ChartOfAccounts
{
    /** @var list<array{key: string, code: string, name: string, name_ur: string, type: AccountType}> */
    public const SYSTEM_ACCOUNTS = [
        ['key' => LedgerAccount::RECEIVABLES, 'code' => '1100', 'name' => 'Accounts receivable', 'name_ur' => 'وصولی کے کھاتے (لینا)', 'type' => AccountType::Asset],
        ['key' => LedgerAccount::MATERIAL_STOCK, 'code' => '1200', 'name' => 'Material stock', 'name_ur' => 'میٹریل کا اسٹاک', 'type' => AccountType::Asset],
        ['key' => LedgerAccount::PAYABLES, 'code' => '2100', 'name' => 'Accounts payable', 'name_ur' => 'ادائیگی کے کھاتے (دینا)', 'type' => AccountType::Liability],
        ['key' => LedgerAccount::WAGES_PAYABLE, 'code' => '2200', 'name' => 'Wages payable', 'name_ur' => 'واجب الادا مزدوری', 'type' => AccountType::Liability],
        ['key' => LedgerAccount::OWNER_CAPITAL, 'code' => '3000', 'name' => 'Owner capital', 'name_ur' => 'مالک کا سرمایہ', 'type' => AccountType::Equity],
        ['key' => LedgerAccount::OWNER_DRAWINGS, 'code' => '3100', 'name' => 'Owner withdrawals', 'name_ur' => 'مالک کی نکاسی', 'type' => AccountType::Equity],
        ['key' => LedgerAccount::OPENING_BALANCE_EQUITY, 'code' => '3900', 'name' => 'Opening balances', 'name_ur' => 'ابتدائی بیلنس', 'type' => AccountType::Equity],
        ['key' => LedgerAccount::WORKER_DEDUCTIONS, 'code' => '4900', 'name' => 'Deductions from workers', 'name_ur' => 'مزدوروں سے کٹوتی', 'type' => AccountType::Income],
        ['key' => LedgerAccount::MATERIAL_SHORTAGES, 'code' => '5900', 'name' => 'Material shortages', 'name_ur' => 'میٹریل کی کمی', 'type' => AccountType::Expense],
    ];

    /** Must run inside the kiln context. Never changes existing rows. */
    public function seedSystemAccounts(): void
    {
        foreach (self::SYSTEM_ACCOUNTS as $account) {
            LedgerAccount::query()->firstOrCreate(['system_key' => $account['key']], [
                'code' => $account['code'],
                'name' => $account['name'],
                'name_ur' => $account['name_ur'],
                'type' => $account['type'],
                'is_active' => true,
            ]);
        }
    }

    /** The ledger account behind a new cash/bank account. */
    public function createTreasuryLedgerAccount(TreasuryKind $kind, string $name, ?string $nameUr): LedgerAccount
    {
        $type = $kind->ledgerType();

        return $this->createAccount($type === AccountType::Liability ? [2001, 2099] : [1001, 1099], $type, $name, $nameUr);
    }

    /**
     * The expense account of a category, created the first time the category is used. Must run
     * inside a transaction: the category row is locked so two first expenses can't both create one.
     */
    public function expenseAccountFor(ExpenseCategory $category): LedgerAccount
    {
        /** @var ExpenseCategory $locked */
        $locked = ExpenseCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

        if ($locked->ledger_account_id !== null) {
            return LedgerAccount::query()->findOrFail($locked->ledger_account_id);
        }

        $account = $this->createAccount([5001, 5499], AccountType::Expense, $locked->name, $locked->name_ur);
        $locked->ledger_account_id = $account->id;
        $locked->save();
        $category->ledger_account_id = $account->id;

        return $account;
    }

    /**
     * The expense account a material's cost moves to when it is used ("Coal used"), created the
     * first time. Must run inside a transaction: the material row is locked.
     */
    public function usageAccountFor(Material $material): LedgerAccount
    {
        /** @var Material $locked */
        $locked = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();

        if ($locked->usage_account_id !== null) {
            return LedgerAccount::query()->findOrFail($locked->usage_account_id);
        }

        [$name, $nameUr] = self::usageAccountNames($locked);
        $account = $this->createAccount([5501, 5899], AccountType::Expense, $name, $nameUr);
        $locked->usage_account_id = $account->id;
        $locked->save();
        $material->usage_account_id = $account->id;

        return $account;
    }

    /**
     * The expense account of a labour category ("Pathai wages"), created with its first earnings.
     * Must run inside a transaction: the category row is locked.
     */
    public function labourAccountFor(LabourCategory $category): LedgerAccount
    {
        /** @var LabourCategory $locked */
        $locked = LabourCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

        if ($locked->ledger_account_id !== null) {
            return LedgerAccount::query()->findOrFail($locked->ledger_account_id);
        }

        [$name, $nameUr] = self::labourAccountNames($locked);
        $account = $this->createAccount([6001, 6499], AccountType::Expense, $name, $nameUr);
        $locked->ledger_account_id = $account->id;
        $locked->save();
        $category->ledger_account_id = $account->id;

        return $account;
    }

    /**
     * Name of a labour category's expense account in English and Urdu (kept in step on rename).
     *
     * @return array{0: string, 1: string|null}
     */
    public static function labourAccountNames(LabourCategory $category): array
    {
        return [$category->name.' wages', $category->name_ur !== null ? $category->name_ur.' (مزدوری)' : null];
    }

    /**
     * Name of a material's usage account in English and Urdu (kept in step when it is renamed).
     *
     * @return array{0: string, 1: string|null}
     */
    public static function usageAccountNames(Material $material): array
    {
        return [$material->name.' used', $material->name_ur !== null ? $material->name_ur.' (استعمال)' : null];
    }

    /**
     * Next free code in the range, taken under a lock.
     *
     * @param  array{int, int}  $range
     */
    private function createAccount(array $range, AccountType $type, string $name, ?string $nameUr): LedgerAccount
    {
        $codes = LedgerAccount::query()
            ->whereBetween('code', [(string) $range[0], (string) $range[1]])
            ->lockForUpdate()
            ->pluck('code')
            ->map(fn ($code) => (int) $code);

        $next = $codes->isEmpty() ? $range[0] : $codes->max() + 1;

        if ($next > $range[1]) {
            throw new LedgerIntegrityException('No free account codes left in this range.');
        }

        return LedgerAccount::query()->create([
            'code' => (string) $next,
            'name' => $name,
            'name_ur' => $nameUr,
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
