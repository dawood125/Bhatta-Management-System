<?php

namespace App\Actions\Materials;

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\MaterialMovementType;
use App\Enums\Permission;
use App\Enums\PurchaseChargeKind;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\MaterialPurchase;
use App\Models\MaterialPurchaseCharge;
use App\Models\Party;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A purchase of materials (entry PU-…).
 *
 * Goods are bought on credit (added to the supplier's account) or paid now from an account.
 * Extra charges (trolley, excavator, loading, ...) are paid with the goods, owed to another
 * party, or paid from an account, and are added to the materials' cost in proportion to each
 * line's value. Stock goes up by the quantities at that landed cost.
 *
 * Entry: debit Material stock with the full cost; credit whoever is owed or paid.
 */
class RecordMaterialPurchase
{
    use GuardsBalances, ValidatesSettings;

    public const MAX_LINES = 20;

    public const MAX_CHARGES = 10;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  purchase_date, payment, supplier_id, account_id, reference, notes,
     *                                       lines[] = {material_id, quantity, rate},
     *                                       charges[] = {kind, description, amount, payee, party_id, account_id}
     */
    public function handle(User $actor, array $input, string $idempotencyKey): MaterialPurchase
    {
        $this->authorizeSettings($actor, Permission::MaterialsRecord);

        if (($existing = $this->findByKey($idempotencyKey)) !== null) {
            return $existing;
        }

        $data = $this->validate($this->cleanInput($input));
        $date = Carbon::createFromFormat('Y-m-d', $data['purchase_date'])->startOfDay();
        $materials = Material::query()->with('unit')->whereKey(array_column($data['lines'], 'material_id'))->get()->keyBy('id');

        $lines = $this->priceLines($data['lines'], $materials);
        $charges = $this->charges($data['charges'] ?? []);
        try {
            $goodsTotal = Money::sum(array_column($lines, 'amount'));
            $chargesTotal = Money::sum(array_column($charges, 'amount'));
            $goodsTotal->add($chargesTotal);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['lines' => __('The total of this purchase is too large.')]);
        }
        $allocated = $this->allocate($chargesTotal, array_column($lines, 'amount'));

        return DB::transaction(function () use ($actor, $data, $date, $lines, $charges, $goodsTotal, $chargesTotal, $allocated, $idempotencyKey): MaterialPurchase {
            $paidNow = $data['payment'] === MaterialPurchase::PAID_NOW;
            $accountIds = array_filter([$paidNow ? (int) $data['account_id'] : null, ...array_column($charges, 'account_id')]);
            $accounts = $this->lockTreasuryAccounts(array_values(array_unique($accountIds)));
            $partyIds = array_filter([$data['supplier_id'] !== null ? (int) $data['supplier_id'] : null, ...array_column($charges, 'party_id')]);
            $parties = Party::query()->whereKey($partyIds)->get()->keyBy('id');
            $supplier = $data['supplier_id'] !== null ? $parties[(int) $data['supplier_id']] : null;

            $this->assertDateAllowed($date, $accounts, $parties);

            // Whoever the goods are owed to (the supplier) or paid from (an account).
            $goodsTarget = $paidNow
                ? ['account' => $accounts[(int) $data['account_id']]->ledgerAccount, 'party' => null]
                : ['account' => $supplier?->controlAccount(), 'party' => $supplier];

            $credits = [];
            $this->addCredit($credits, $goodsTarget, $goodsTotal);
            foreach ($charges as $charge) {
                $target = match ($charge['payee']) {
                    MaterialPurchaseCharge::WITH_GOODS => $goodsTarget,
                    MaterialPurchaseCharge::TO_PARTY => ['account' => $parties[$charge['party_id']]->controlAccount(), 'party' => $parties[$charge['party_id']]],
                    default => ['account' => $accounts[$charge['account_id']]->ledgerAccount, 'party' => null],
                };
                $this->addCredit($credits, $target, $charge['amount']);
            }

            $total = $goodsTotal->add($chargesTotal);
            $pending = [PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::MATERIAL_STOCK), $total)];
            foreach ($credits as $credit) {
                $pending[] = PendingLine::credit($credit['account'], $credit['amount'])->forParty($credit['party']);
            }

            $entry = $this->poster->post(JournalType::MaterialPurchase, $date, $pending, $idempotencyKey, $actor, $data['notes']);

            // Two identical requests raced each other and the other one posted first.
            if (! $entry->wasRecentlyCreated) {
                return MaterialPurchase::query()->where('journal_entry_id', $entry->id)->firstOrFail();
            }

            $purchase = MaterialPurchase::query()->create([
                'journal_entry_id' => $entry->id,
                'purchase_date' => $date->format('Y-m-d'),
                'supplier_id' => $supplier?->id,
                'payment' => $data['payment'],
                'treasury_account_id' => $paidNow ? (int) $data['account_id'] : null,
                'reference' => $data['reference'],
                'notes' => $data['notes'],
                'goods_total' => $goodsTotal,
                'charges_total' => $chargesTotal,
                'total' => $total,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $index => $line) {
                $cost = $line['amount']->add($allocated[$index]);
                $purchaseLine = $purchase->lines()->create([
                    'line_no' => $index + 1,
                    'material_id' => $line['material']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => $line['amount'],
                    'charges' => $allocated[$index],
                    'cost' => $cost,
                ]);

                MaterialMovement::query()->create([
                    'material_id' => $line['material']->id,
                    'movement_date' => $date->format('Y-m-d'),
                    'type' => MaterialMovementType::Purchase,
                    'quantity_in' => $line['quantity'],
                    'quantity_out' => Quantity::zero(),
                    'value_in' => $cost,
                    'value_out' => Money::zero(),
                    'journal_entry_id' => $entry->id,
                    'material_purchase_line_id' => $purchaseLine->id,
                    'created_by' => $actor->id,
                ]);
            }

            foreach ($charges as $index => $charge) {
                $purchase->charges()->create([
                    'line_no' => $index + 1,
                    'kind' => $charge['kind'],
                    'description' => $charge['description'],
                    'amount' => $charge['amount'],
                    'payee' => $charge['payee'],
                    'party_id' => $charge['party_id'],
                    'treasury_account_id' => $charge['account_id'],
                ]);
            }

            foreach ($accounts as $account) {
                $this->assertNotNegative($account, $paidNow && $account->id === (int) $data['account_id'] ? 'account_id' : 'charges');
            }

            return $purchase;
        });
    }

    /**
     * Split the extra charges over the lines in proportion to their value. Rounding remainders go
     * to the lines with the largest fractions, so the shares always add up to the exact total.
     *
     * @param  list<Money>  $weights
     * @return list<Money>
     */
    public function allocate(Money $total, array $weights): array
    {
        $sum = Money::sum($weights)->paisa;
        if ($total->isZero() || $sum === 0) {
            return array_map(fn () => Money::zero(), $weights);
        }

        // Big-integer arithmetic: total × weight can exceed 64 bits for very large purchases.
        $shares = [];
        $fractions = [];
        foreach ($weights as $index => $weight) {
            $product = BigInteger::of($total->paisa)->multipliedBy($weight->paisa);
            $shares[$index] = $product->quotient($sum)->toInt();
            $fractions[$index] = $product->remainder($sum)->toInt();
        }

        arsort($fractions);
        $left = $total->paisa - array_sum($shares);
        foreach (array_keys($fractions) as $index) {
            if ($left === 0) {
                break;
            }
            $shares[$index]++;
            $left--;
        }

        return array_map(fn (int $paisa) => Money::fromPaisa($paisa), $shares);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function cleanInput(array $input): array
    {
        $cleanList = function (mixed $rows, array $optional): mixed {
            if (! is_array($rows)) {
                return $rows;
            }

            return array_values(array_map(fn ($row) => is_array($row) ? $this->clean($row, $optional) : $row, $rows));
        };

        $lines = $cleanList($input['lines'] ?? null, []);
        $charges = $cleanList($input['charges'] ?? [], ['description', 'party_id', 'account_id']);
        $input = $this->clean(array_diff_key($input, ['lines' => true, 'charges' => true]), ['supplier_id', 'account_id', 'reference', 'notes']);

        // Each charge keeps only the field its payee uses.
        if (is_array($charges)) {
            foreach ($charges as $index => $charge) {
                if (! is_array($charge)) {
                    continue;
                }
                $payee = $charge['payee'] ?? null;
                $charges[$index]['party_id'] = $payee === MaterialPurchaseCharge::TO_PARTY ? ($charge['party_id'] ?? null) : null;
                $charges[$index]['account_id'] = $payee === MaterialPurchaseCharge::FROM_ACCOUNT ? ($charge['account_id'] ?? null) : null;
            }
        }

        // Paid now uses an account; on credit uses the supplier's account.
        if (($input['payment'] ?? null) === MaterialPurchase::ON_CREDIT) {
            $input['account_id'] = null;
        }

        return $input + ['lines' => $lines, 'charges' => $charges];
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
            'purchase_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment' => ['required', Rule::in([MaterialPurchase::ON_CREDIT, MaterialPurchase::PAID_NOW])],
            'supplier_id' => ['nullable', 'required_if:payment,'.MaterialPurchase::ON_CREDIT, 'integer', $activeParty],
            'account_id' => ['nullable', 'required_if:payment,'.MaterialPurchase::PAID_NOW, 'integer', $activeAccount],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.material_id' => ['required', 'integer', 'distinct', Rule::exists('materials', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
            'lines.*.quantity' => ['required'],
            'lines.*.rate' => ['required', new MoneyAmount],
            'charges' => ['present', 'array', 'max:'.self::MAX_CHARGES],
            'charges.*' => ['required', 'array'],
            'charges.*.kind' => ['required', Rule::enum(PurchaseChargeKind::class)],
            'charges.*.description' => ['nullable', 'string', 'max:150'],
            'charges.*.amount' => ['required', new MoneyAmount],
            'charges.*.payee' => ['required', Rule::in([MaterialPurchaseCharge::WITH_GOODS, MaterialPurchaseCharge::TO_PARTY, MaterialPurchaseCharge::FROM_ACCOUNT])],
            'charges.*.party_id' => ['nullable', 'required_if:charges.*.payee,'.MaterialPurchaseCharge::TO_PARTY, 'integer', $activeParty],
            'charges.*.account_id' => ['nullable', 'required_if:charges.*.payee,'.MaterialPurchaseCharge::FROM_ACCOUNT, 'integer', $activeAccount],
        ], [
            'supplier_id.required_if' => __('Choose the supplier, or choose "Paid now".'),
            'supplier_id.exists' => __('This party is archived or does not exist.'),
            'account_id.required_if' => __('Choose the account it was paid from.'),
            'account_id.exists' => __('This account is archived or does not exist.'),
            'lines.required' => __('Add at least one material.'),
            'lines.min' => __('Add at least one material.'),
            'lines.max' => __('A purchase can have at most :max materials.'),
            'lines.*.material_id.distinct' => __('Each material only once per purchase.'),
            'lines.*.material_id.exists' => __('This material is archived or does not exist.'),
            'lines.*.quantity.required' => __('Enter the quantity.'),
            'charges.max' => __('A purchase can have at most :max charges.'),
            'charges.*.party_id.required_if' => __('Choose a party.'),
            'charges.*.party_id.exists' => __('This party is archived or does not exist.'),
            'charges.*.account_id.required_if' => __('Choose an account.'),
            'charges.*.account_id.exists' => __('This account is archived or does not exist.'),
        ], [
            'purchase_date' => __('date'),
            'lines.*.material_id' => __('material'),
            'lines.*.rate' => __('rate'),
            'charges.*.kind' => __('type'),
            'charges.*.amount' => __('amount'),
            'charges.*.payee' => __('paid how'),
        ])->validate();
    }

    /**
     * Quantity × rate for each line, with the material's own decimal places.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Material>  $materials
     * @return list<array{material: Material, quantity: Quantity, rate: Money, amount: Money}>
     */
    private function priceLines(array $rows, Collection $materials): array
    {
        $lines = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $material = $materials[(int) $row['material_id']];
            $decimals = $material->unit->decimals;

            try {
                $quantity = Quantity::fromInput((string) $row['quantity'], $decimals);
            } catch (InvalidArgumentException) {
                $errors["lines.{$index}.quantity"] = $decimals === 0
                    ? __('Enter a whole number.')
                    : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $decimals]);

                continue;
            }

            if (! $quantity->isPositive()) {
                $errors["lines.{$index}.quantity"] = __('The quantity must be more than zero.');

                continue;
            }

            $rate = Money::fromRupees((string) $row['rate']);

            try {
                $amount = $rate->timesQuantity($quantity->toDecimal());
            } catch (InvalidArgumentException) {
                $errors["lines.{$index}.rate"] = __('The amount of this line is too large.');

                continue;
            }

            if (! $amount->isPositive()) {
                $errors["lines.{$index}.rate"] = __('Quantity × rate comes to less than one paisa.');

                continue;
            }

            $lines[] = ['material' => $material, 'quantity' => $quantity, 'rate' => $rate, 'amount' => $amount];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{kind: PurchaseChargeKind, description: string|null, amount: Money, payee: string, party_id: int|null, account_id: int|null}>
     */
    private function charges(array $rows): array
    {
        return array_map(fn (array $row) => [
            'kind' => PurchaseChargeKind::from($row['kind']),
            'description' => $row['description'] ?? null,
            'amount' => Money::fromRupees((string) $row['amount']),
            'payee' => $row['payee'],
            'party_id' => isset($row['party_id']) ? (int) $row['party_id'] : null,
            'account_id' => isset($row['account_id']) ? (int) $row['account_id'] : null,
        ], $rows);
    }

    /**
     * Add an amount to the credit line of the same account and party, so each one appears once.
     *
     * @param  array<string, array{account: LedgerAccount, party: Party|null, amount: Money}>  $credits
     * @param  array{account: LedgerAccount|null, party: Party|null}  $target
     */
    private function addCredit(array &$credits, array $target, Money $amount): void
    {
        if ($amount->isZero() || $target['account'] === null) {
            return;
        }

        $key = $target['account']->id.':'.($target['party']->id ?? 0);
        $credits[$key] = [
            'account' => $target['account'],
            'party' => $target['party'],
            'amount' => isset($credits[$key]) ? $credits[$key]['amount']->add($amount) : $amount,
        ];
    }

    /**
     * Nothing may be dated before the opening date of an account or party it touches.
     *
     * @param  Collection<int, TreasuryAccount>  $accounts
     * @param  Collection<int, Party>  $parties
     */
    private function assertDateAllowed(Carbon $date, Collection $accounts, Collection $parties): void
    {
        foreach ([...$accounts->all(), ...$parties->all()] as $record) {
            if ($date->lt($record->opening_date)) {
                throw ValidationException::withMessages(['purchase_date' => __('The date is before the opening date of :account (:date).', [
                    'account' => $record->displayName(),
                    'date' => $record->opening_date->format('d-m-Y'),
                ])]);
            }
        }
    }

    private function findByKey(string $idempotencyKey): ?MaterialPurchase
    {
        $entry = JournalEntry::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->type !== JournalType::MaterialPurchase) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return MaterialPurchase::query()->where('journal_entry_id', $entry->id)->firstOrFail();
    }
}
