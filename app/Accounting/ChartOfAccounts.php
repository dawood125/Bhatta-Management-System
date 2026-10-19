<?php

namespace App\Accounting;

use App\Enums\AccountType;
use App\Enums\TreasuryKind;
use App\Models\LedgerAccount;

/**
 * Creates the ledger accounts the system needs. Each module adds its own system accounts when
 * it is built (receivables, payables, sales, ...), so nothing unused is created early.
 *
 * Code ranges: 1xxx assets (1001-1099 cash/bank, 1100 receivables), 2xxx liabilities
 * (2001-2099 credit cards, 2100 payables), 3xxx equity.
 */
class ChartOfAccounts
{
    /** @var list<array{key: string, code: string, name: string, name_ur: string, type: AccountType}> */
    public const SYSTEM_ACCOUNTS = [
        ['key' => LedgerAccount::RECEIVABLES, 'code' => '1100', 'name' => 'Accounts receivable', 'name_ur' => 'وصولی کے کھاتے (لینا)', 'type' => AccountType::Asset],
        ['key' => LedgerAccount::PAYABLES, 'code' => '2100', 'name' => 'Accounts payable', 'name_ur' => 'ادائیگی کے کھاتے (دینا)', 'type' => AccountType::Liability],
        ['key' => LedgerAccount::OWNER_CAPITAL, 'code' => '3000', 'name' => 'Owner capital', 'name_ur' => 'مالک کا سرمایہ', 'type' => AccountType::Equity],
        ['key' => LedgerAccount::OWNER_DRAWINGS, 'code' => '3100', 'name' => 'Owner withdrawals', 'name_ur' => 'مالک کی نکاسی', 'type' => AccountType::Equity],
        ['key' => LedgerAccount::OPENING_BALANCE_EQUITY, 'code' => '3900', 'name' => 'Opening balances', 'name_ur' => 'ابتدائی بیلنس', 'type' => AccountType::Equity],
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
        $range = $type === AccountType::Liability ? [2001, 2099] : [1001, 1099];

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
