<?php

namespace App\Reports;

use App\Accounting\LedgerQueries;
use App\Enums\AccountType;
use App\Models\LedgerAccount;
use App\Models\TreasuryAccount;
use App\Support\Kiln\CurrentKiln;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit and loss and the balance sheet, straight from the journal (so they always agree with
 * the trial balance and with each other).
 *
 * Production costs (materials, fuel, labour) are counted when they are spent; bricks in stock
 * are shown in quantity only, not valued (proposed default for Q-42). Material stock is an
 * asset at its average cost (M5).
 */
class FinancialStatements
{
    /** Expense groups by account code range (see ChartOfAccounts). */
    public const GROUP_MATERIALS = 'materials';

    public const GROUP_LABOUR = 'labour';

    public const GROUP_EXPENSES = 'expenses';

    public const GROUP_OTHER = 'other';

    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly LedgerQueries $ledger,
    ) {}

    /**
     * Income and expenses of a period. Net profit = income − expenses.
     *
     * @return array{income: list<array{account: LedgerAccount, amount: Money}>, total_income: Money, groups: list<array{key: string, label: string, rows: list<array{account: LedgerAccount, amount: Money}>, total: Money}>, total_expenses: Money, net: Money}
     */
    public function profitAndLoss(CarbonInterface $from, CarbonInterface $to): array
    {
        $sums = $this->sums($from, $to)->keyBy('ledger_account_id');
        $accounts = LedgerAccount::query()
            ->whereIn('type', [AccountType::Income, AccountType::Expense])
            ->whereKey($sums->keys()->all())
            ->orderBy('code')
            ->get();

        $income = [];
        $groups = [];
        foreach ([self::GROUP_MATERIALS, self::GROUP_LABOUR, self::GROUP_EXPENSES, self::GROUP_OTHER] as $key) {
            $groups[$key] = ['key' => $key, 'label' => self::groupLabel($key), 'rows' => [], 'total' => Money::zero()];
        }

        foreach ($accounts as $account) {
            $net = (int) $sums[$account->id]->debits - (int) $sums[$account->id]->credits;
            $amount = Money::fromPaisa($account->type === AccountType::Income ? -$net : $net);
            if ($amount->isZero()) {
                continue;
            }

            if ($account->type === AccountType::Income) {
                $income[] = ['account' => $account, 'amount' => $amount];

                continue;
            }

            $key = self::groupOf($account);
            $groups[$key]['rows'][] = ['account' => $account, 'amount' => $amount];
            $groups[$key]['total'] = $groups[$key]['total']->add($amount);
        }

        $totalIncome = Money::sum(array_column($income, 'amount'));
        $totalExpenses = Money::sum(array_column($groups, 'total'));

        return [
            'income' => $income,
            'total_income' => $totalIncome,
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['rows'] !== [])),
            'total_expenses' => $totalExpenses,
            'net' => $totalIncome->subtract($totalExpenses),
        ];
    }

    /**
     * Profit (or loss) of everything up to and including a date: the retained earnings.
     */
    public function retainedEarnings(CarbonInterface $asOf): Money
    {
        $sums = $this->sums(null, $asOf)->keyBy('ledger_account_id');
        $types = LedgerAccount::query()->whereKey($sums->keys()->all())->pluck('type', 'id');

        $paisa = 0;
        foreach ($sums as $id => $row) {
            $type = $types[$id] ?? null;
            if ($type === AccountType::Income || $type === AccountType::Expense) {
                $paisa += (int) $row->credits - (int) $row->debits;
            }
        }

        return Money::fromPaisa($paisa);
    }

    /**
     * What the kiln owns, owes and is worth on a date. Customers and others are split by the
     * side of their balance (who owes us is an asset, whom we owe is a liability), and so are
     * workers (wages due vs advances). Assets always equal liabilities plus equity.
     *
     * @return array{assets: list<array{label: string, amount: Money, treasury_id?: int}>, total_assets: Money, liabilities: list<array{label: string, amount: Money, treasury_id?: int}>, total_liabilities: Money, equity: list<array{label: string, amount: Money}>, total_equity: Money, balanced: bool}
     */
    public function balanceSheet(CarbonInterface $asOf): array
    {
        $accounts = LedgerAccount::query()->orderBy('code')->get();
        $balances = $this->ledger->balances($accounts->pluck('id')->all(), $asOf);
        $treasury = TreasuryAccount::query()->pluck('id', 'ledger_account_id');
        // Parties: the debit side owes us, the credit side is owed. Workers: the debit side is
        // advances (they owe us), the credit side is wages due.
        [$receivable, $payable] = $this->split('party_id', $asOf);
        [$advances, $wagesDue] = $this->split('worker_id', $asOf);

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($accounts as $account) {
            $amount = $balances[$account->id] ?? Money::zero();

            // Party and worker accounts are replaced by their split below.
            if ($account->requiresParty() || $account->requiresWorker() || $amount->isZero()) {
                continue;
            }

            $row = ['label' => $account->displayName(), 'amount' => $amount] + (isset($treasury[$account->id]) ? ['treasury_id' => (int) $treasury[$account->id]] : []);

            if ($account->type === AccountType::Asset) {
                $assets[] = $row;
            } elseif ($account->type === AccountType::Liability) {
                $liabilities[] = $row;
            } elseif ($account->type === AccountType::Equity) {
                $equity[] = $row;
            }
        }

        if ($receivable->isPositive()) {
            $assets[] = ['label' => __('Customers and others who owe us'), 'amount' => $receivable];
        }
        if ($advances->isPositive()) {
            $assets[] = ['label' => __('Advances to workers'), 'amount' => $advances];
        }
        if ($payable->isPositive()) {
            $liabilities[] = ['label' => __('Suppliers and others we owe'), 'amount' => $payable];
        }
        if ($wagesDue->isPositive()) {
            $liabilities[] = ['label' => __('Wages due to workers'), 'amount' => $wagesDue];
        }

        $profit = $this->retainedEarnings($asOf);
        if (! $profit->isZero()) {
            $equity[] = ['label' => __('Profit to date'), 'amount' => $profit];
        }

        $totalAssets = Money::sum(array_column($assets, 'amount'));
        $totalLiabilities = Money::sum(array_column($liabilities, 'amount'));
        $totalEquity = Money::sum(array_column($equity, 'amount'));

        return [
            'assets' => $assets,
            'total_assets' => $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => $totalLiabilities,
            'equity' => $equity,
            'total_equity' => $totalEquity,
            'balanced' => $totalAssets->equals($totalLiabilities->add($totalEquity)),
        ];
    }

    /** Which expense group an account belongs to. */
    public static function groupOf(LedgerAccount $account): string
    {
        $code = (int) $account->code;

        return match (true) {
            ($code >= 5501 && $code <= 5899) || $account->system_key === LedgerAccount::MATERIAL_SHORTAGES => self::GROUP_MATERIALS,
            $code >= 6001 && $code <= 6499 => self::GROUP_LABOUR,
            $code >= 5001 && $code <= 5499 => self::GROUP_EXPENSES,
            default => self::GROUP_OTHER,
        };
    }

    public static function groupLabel(string $key): string
    {
        return match ($key) {
            self::GROUP_MATERIALS => __('Materials and fuel used'),
            self::GROUP_LABOUR => __('Labour'),
            self::GROUP_EXPENSES => __('Running expenses'),
            default => __('Other costs'),
        };
    }

    /**
     * Debit and credit totals per account for a period (or everything up to $to).
     *
     * @return Collection<int, \stdClass> rows of ledger_account_id, debits, credits
     */
    private function sums(?CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from->format('Y-m-d')))
            ->where('journal_entries.entry_date', '<=', $to->format('Y-m-d'))
            ->groupBy('journal_lines.ledger_account_id')
            ->select('journal_lines.ledger_account_id')
            ->selectRaw('SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get();
    }

    /**
     * Sub-ledger balances on a date (per party or per worker) split by side: [total of the
     * balances on the debit side, total of those on the credit side].
     *
     * @return array{0: Money, 1: Money}
     */
    private function split(string $column, CarbonInterface $asOf): array
    {
        $perHolder = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.kiln_id', $this->currentKiln->id())
            ->whereNotNull("journal_lines.{$column}")
            ->where('journal_entries.entry_date', '<=', $asOf->format('Y-m-d'))
            ->groupBy("journal_lines.{$column}")
            ->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net');

        $totals = DB::query()->fromSub($perHolder, 'h')
            ->selectRaw('COALESCE(SUM(CASE WHEN net > 0 THEN net ELSE 0 END), 0) AS debit_side')
            ->selectRaw('COALESCE(SUM(CASE WHEN net < 0 THEN -net ELSE 0 END), 0) AS credit_side')
            ->first();

        return [Money::fromPaisa((int) ($totals->debit_side ?? 0)), Money::fromPaisa((int) ($totals->credit_side ?? 0))];
    }
}
