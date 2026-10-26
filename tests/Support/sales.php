<?php

use App\Actions\Sales\RecordDispatch;
use App\Actions\Sales\RecordSale;
use App\Models\Party;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\SaleLine;
use App\Models\User;
use App\Sales\CustomerPayments;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Make saleable stock through the real production entries (Pathai → loading → firing → Nakaasi).
 *
 * @param  array<string, string>  $grades  product code => bricks
 */
function makeSaleableStock(User $actor, array $grades): void
{
    $total = (string) array_sum(array_map('intval', $grades));
    recordPathai($actor, $total);
    recordLoading($actor, $total);
    recordFiring($actor, $total);
    recordNakaasi($actor, $grades);
}

/**
 * Record a sale through the real action.
 *
 * @param  array<string, array{0: string, 1: string, 2?: string}>  $lines  product code => [quantity, rate, discount]
 */
function recordSale(User $actor, Party $customer, array $lines, array $extra = [], ?string $key = null): Sale
{
    $rows = [];
    foreach ($lines as $code => $line) {
        $rows[] = ['product_id' => (string) product($code)->id, 'quantity' => $line[0], 'rate' => $line[1], 'discount' => $line[2] ?? ''];
    }

    return app(RecordSale::class)->handle($actor, array_merge([
        'sale_date' => now()->format('Y-m-d'),
        'customer_id' => (string) $customer->id,
        'agent_id' => '',
        'notes' => '',
        'lines' => $rows,
        'deliver_now' => false,
        'driver_id' => '',
        'vehicle' => '',
        'carriage' => '',
        'received' => '',
        'account_id' => '',
    ], $extra), $key ?? (string) Str::uuid());
}

/**
 * Record a delivery of a sale through the real action.
 *
 * @param  array<string, string>  $quantities  product code => quantity
 */
function recordDispatch(User $actor, Sale $sale, array $quantities, array $extra = [], ?string $key = null): SaleDispatch
{
    $lines = [];
    foreach ($sale->lines()->with('product')->get() as $line) {
        /** @var SaleLine $line */
        $lines[] = ['sale_line_id' => (string) $line->id, 'quantity' => $quantities[$line->product->code] ?? ''];
    }

    return app(RecordDispatch::class)->handle($actor, $sale, array_merge([
        'dispatch_date' => now()->format('Y-m-d'),
        'driver_id' => '',
        'vehicle' => '',
        'carriage' => '',
        'notes' => '',
        'lines' => $lines,
    ], $extra), $key ?? (string) Str::uuid());
}

/**
 * The validation errors a call throws (field => first message); fails the test if it throws none.
 *
 * @return array<string, string>
 */
function validationErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_map(fn (array $messages) => $messages[0], $e->errors());
    }

    test()->fail('Expected a validation error.');
}

/** A party's balance as text: positive = they owe us, negative = we owe them. */
function partyBalance(Party $party): string
{
    return $party->balance()->toDecimal();
}

/**
 * What the customer has paid of a sale, as text.
 *
 * @return array{due: string, paid: string, outstanding: string, status: string|null}
 */
function salePayment(Sale $sale): array
{
    $payment = app(CustomerPayments::class)->forSale($sale->fresh());

    return [
        'due' => $payment['due']->toDecimal(),
        'paid' => $payment['paid']->toDecimal(),
        'outstanding' => $payment['outstanding']->toDecimal(),
        'status' => $payment['status']?->value,
    ];
}
