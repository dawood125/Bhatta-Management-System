<?php

namespace App\Actions\Sales;

use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Accounting\Concerns\GuardsBalances;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\TreasuryAccount;
use App\Models\Unit;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A new sale (invoice INV-…). The customer owes the total at once (credit sale); the bricks are
 * reserved until they are delivered, and a sale for more than is free to sell is refused
 * (stock reserved on confirmation, reduced on each delivery: proposed default for Q-30).
 *
 * Optionally, in the same step: money received from the customer now (a receipt RCV-…), and the
 * bricks delivered now (a delivery DS-… with everything on the sale). Each part is its own entry,
 * so the customer's statement shows the sale, the delivery's carriage and the receipt apart.
 * When sales emails are on, the invoice is emailed to the owner after the sale is saved.
 */
class RecordSale
{
    use ChecksSales, GuardsBalances, ValidatesSettings;

    public const MAX_LINES = 20;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly SaleStock $saleStock,
        private readonly RecordDispatch $recordDispatch,
        private readonly EmailSaleInvoice $emailInvoice,
    ) {}

    /**
     * @param  array<string, mixed>  $input  sale_date, customer_id, agent_id, notes,
     *                                       lines[] = {product_id, quantity, rate, discount},
     *                                       deliver_now, driver_id, vehicle, carriage,
     *                                       received, account_id
     */
    public function handle(User $actor, array $input, string $idempotencyKey): Sale
    {
        $this->authorizeSettings($actor, Permission::SalesRecord);

        if (($existing = $this->existing($idempotencyKey)) !== null) {
            return $existing;
        }

        $input = $this->cleanInput($input);
        $data = $this->validate($input);

        $date = Carbon::createFromFormat('Y-m-d', $data['sale_date'])->startOfDay();
        $customer = $this->partyWithRole($data['customer_id'], PartyRole::Customer, 'customer_id', __('Choose an active party with the customer role.'));
        $agent = filled($data['agent_id'] ?? null)
            ? $this->partyWithRole($data['agent_id'], PartyRole::Agent, 'agent_id', __('Choose an active party with the sales agent role.'))
            : null;

        $products = Product::query()->with('unit')->whereKey(array_column($data['lines'], 'product_id'))->get()->keyBy('id');
        $lines = $this->lines(array_values($data['lines']), $products->all());
        $total = $this->total($lines);

        $received = blank($data['received'] ?? null) ? Money::zero() : Money::fromRupees((string) $data['received']);
        if ($received->isPositive()) {
            Gate::forUser($actor)->authorize(Permission::PaymentsRecord->value);

            if (blank($data['account_id'] ?? null)) {
                throw ValidationException::withMessages(['account_id' => __('Choose the account the money went into.')]);
            }
        }

        $deliverNow = (bool) ($data['deliver_now'] ?? false);
        $transport = $deliverNow ? $this->recordDispatch->transport($data, $customer) : null;

        try {
            $sale = DB::transaction(function () use ($actor, $data, $date, $customer, $agent, $lines, $total, $received, $transport, $idempotencyKey): Sale {
                $this->lockStock();
                $this->assertAfterOpening($customer, $date, 'sale_date');
                $this->assertFreeStock($lines);

                $entry = $this->poster->post(JournalType::Sale, $date, [
                    PendingLine::debit($customer->controlAccount(), $total)->forParty($customer),
                    PendingLine::credit(LedgerAccount::bySystemKey(LedgerAccount::SALES), $total),
                ], $idempotencyKey, $actor);

                $receipt = null;
                if ($received->isPositive()) {
                    /** @var TreasuryAccount $account */
                    $account = $this->lockTreasuryAccounts([(int) $data['account_id']])->first();
                    if ($date->lt($account->opening_date)) {
                        throw ValidationException::withMessages(['account_id' => __('The date is before the opening date of :account (:date).', [
                            'account' => $account->displayName(),
                            'date' => $account->opening_date->format('d-m-Y'),
                        ])]);
                    }

                    // The same shape as a receipt voucher: line 1 is the account the money went into.
                    $receipt = $this->poster->post(JournalType::Receipt, $date, [
                        PendingLine::debit($account->ledgerAccount, $received),
                        PendingLine::credit($customer->controlAccount(), $received)->forParty($customer),
                    ], substr(trim($idempotencyKey), 0, 58).':rcv', $actor);
                }

                $sale = Sale::query()->create([
                    'number' => $entry->number,
                    'sale_date' => $date->format('Y-m-d'),
                    'customer_id' => $customer->id,
                    'agent_id' => $agent?->id,
                    'journal_entry_id' => $entry->id,
                    'total' => $total,
                    'status' => SaleStatus::Open,
                    'notes' => $data['notes'] ?? null,
                    'receipt_entry_id' => $receipt?->id,
                    'idempotency_key' => trim($idempotencyKey),
                    'created_by' => $actor->id,
                ]);

                $items = [];
                foreach ($lines as $index => $line) {
                    $items[$index] = [
                        'line' => SaleLine::query()->create([
                            'sale_id' => $sale->id,
                            'line_no' => $index + 1,
                            'product_id' => $line['product']->id,
                            'quantity' => $line['quantity'],
                            'rate' => $line['rate'],
                            'rate_per' => $line['per'],
                            'discount' => $line['discount'],
                            'amount' => $line['amount'],
                        ])->setRelation('product', $line['product']),
                        'quantity' => $line['quantity'],
                    ];
                }

                if ($transport !== null) {
                    $sale->setRelation('customer', $customer);
                    $this->recordDispatch->deliver($actor, $sale, $date, $items, $transport, null, substr(trim($idempotencyKey), 0, 58).':ds');
                }

                $this->emailInvoice->queueIfEnabled($sale, $actor);

                return $sale;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (($existing = $this->existing($idempotencyKey)) !== null) {
                return $existing;
            }

            throw $e;
        }

        return $sale->refresh();
    }

    /**
     * The rate of a product is per 1,000 bricks for products counted in bricks, and per unit for
     * everything else (e.g. Rora by the trolley).
     */
    public static function ratePer(Product $product): int
    {
        return $product->unit->code === Unit::BRICK ? 1000 : 1;
    }

    /**
     * Each line worked out: quantity, rate, discount and amount. Errors point at the line's field.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, Product>  $products
     * @return list<array{product: Product, quantity: Quantity, rate: Money, per: int, discount: Money, amount: Money}>
     */
    private function lines(array $rows, array $products): array
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $product = $products[(int) $row['product_id']];
            $quantity = $this->quantity($row['quantity'] ?? '', $product->unit->decimals, "lines.{$index}.quantity");
            $rate = Money::fromRupees((string) $row['rate']);
            $discount = blank($row['discount'] ?? null) ? Money::zero() : Money::fromRupees((string) $row['discount']);
            $per = self::ratePer($product);

            try {
                $gross = $rate->timesQuantity($quantity->toDecimal(), $per);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('This amount is too large.')]);
            }

            if ($discount->greaterThan($gross)) {
                throw ValidationException::withMessages(["lines.{$index}.discount" => __('The discount cannot be more than the amount (:amount).', ['amount' => $gross->formatWithCurrency()])]);
            }

            $lines[] = ['product' => $product, 'quantity' => $quantity, 'rate' => $rate, 'per' => $per, 'discount' => $discount, 'amount' => $gross->subtract($discount)];
        }

        return $lines;
    }

    /**
     * @param  list<array{amount: Money}>  $lines
     */
    private function total(array $lines): Money
    {
        try {
            $total = Money::sum(array_column($lines, 'amount'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['lines' => __('The total of this sale is too large.')]);
        }

        if (! $total->isPositive()) {
            throw ValidationException::withMessages(['lines' => __('The total of the sale must be more than zero.')]);
        }

        return $total;
    }

    /**
     * Each product must be free to sell: in stock now, less what open sales still have to collect.
     *
     * @param  list<array{product: Product, quantity: Quantity}>  $lines
     */
    private function assertFreeStock(array $lines): void
    {
        $availability = $this->saleStock->availability(array_column($lines, 'product'));

        foreach ($lines as $index => $line) {
            $free = $availability[$line['product']->id];
            if ($line['quantity']->greaterThan($free['free'])) {
                $decimals = $line['product']->unit->decimals;
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Only :free free to sell (in stock :stock, waiting for other sales :reserved).', [
                    'free' => $free['free']->isNegative() ? '0' : $free['free']->format($decimals),
                    'stock' => $free['stock']->format($decimals),
                    'reserved' => $free['reserved']->format($decimals),
                ])]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function cleanInput(array $input): array
    {
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : $input['lines'] ?? null;
        $input = $this->clean(array_diff_key($input, ['lines' => true]), ['agent_id', 'notes', 'driver_id', 'vehicle', 'carriage', 'received', 'account_id']);

        if (is_array($lines)) {
            foreach ($lines as $index => $line) {
                $lines[$index] = is_array($line) ? $this->clean($line, ['discount']) : $line;
            }
        }

        $input['lines'] = $lines;
        $input['deliver_now'] = filter_var($input['deliver_now'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $kilnId = $this->kilnId();
        $saleableProduct = Rule::exists('products', 'id')->where('kiln_id', $kilnId)->where('is_active', true)->where('is_saleable', true);

        return Validator::make($input, [
            'sale_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', $saleableProduct],
            'lines.*.quantity' => ['required', 'string', 'max:30'],
            'lines.*.rate' => ['required', new MoneyAmount],
            'lines.*.discount' => ['nullable', new MoneyAmount(allowZero: true)],
            'deliver_now' => ['boolean'],
            'received' => ['nullable', new MoneyAmount(allowZero: true)],
            'account_id' => ['nullable', 'integer', Rule::exists('treasury_accounts', 'id')->where('kiln_id', $kilnId)->where('is_active', true)],
        ] + RecordDispatch::transportRules(), [
            'lines.required' => __('Add at least one line.'),
            'lines.min' => __('Add at least one line.'),
            'lines.max' => __('A sale can have at most :max lines.'),
            'lines.*.product_id.distinct' => __('This grade is already on the sale.'),
            'lines.*.product_id.exists' => __('This product is archived or not for sale.'),
            'account_id.exists' => __('This account is archived or does not exist.'),
        ], [
            'sale_date' => __('date'),
            'customer_id' => __('customer'),
            'agent_id' => __('sales agent'),
            'notes' => __('note'),
            'lines.*.product_id' => __('grade'),
            'lines.*.quantity' => __('quantity'),
            'lines.*.rate' => __('rate'),
            'lines.*.discount' => __('discount'),
            'received' => __('amount received'),
            'account_id' => __('account'),
        ] + RecordDispatch::transportAttributes())->validate();
    }

    private function existing(string $idempotencyKey): ?Sale
    {
        $sale = Sale::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($sale === null && DB::table('journal_entries')->where('idempotency_key', trim($idempotencyKey))->where('type', '!=', JournalType::Sale->value)->exists()) {
            throw new LedgerIntegrityException('This idempotency key was already used for a different kind of entry.');
        }

        return $sale;
    }
}
