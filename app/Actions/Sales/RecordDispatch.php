<?php

namespace App\Actions\Sales;

use App\Accounting\BooksLock;
use App\Accounting\DocumentNumbers;
use App\Accounting\JournalPoster;
use App\Accounting\LedgerIntegrityException;
use App\Accounting\PendingLine;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Models\BrickMovement;
use App\Models\Party;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\SaleDispatchLine;
use App\Models\SaleLine;
use App\Models\User;
use App\Production\BrickStock;
use App\Rules\MoneyAmount;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A delivery (trip DS-…): bricks of a sale that leave the kiln. They come out of stock (never
 * below zero on that day or any later day) and no longer count as reserved. When the kiln
 * arranged the transport, the carriage is charged to the customer and owed to the driver (D-013):
 * it is neither income nor expense. A sale can be delivered over several trips.
 */
class RecordDispatch
{
    use ChecksSales, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly BrickStock $brickStock,
        private readonly SaleStock $saleStock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  dispatch_date, driver_id, vehicle, carriage, notes,
     *                                       lines[] = {sale_line_id, quantity}
     */
    public function handle(User $actor, Sale $sale, array $input, string $idempotencyKey): SaleDispatch
    {
        $this->authorizeSettings($actor, Permission::SalesRecord);

        if (($existing = $this->existing($idempotencyKey, $sale)) !== null) {
            return $existing;
        }

        $input = $this->clean($input, ['driver_id', 'vehicle', 'carriage', 'notes']);
        $data = Validator::make($input, [
            'dispatch_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'array'],
            'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'string', 'max:30'],
        ] + self::transportRules(), [], [
            'dispatch_date' => __('date'),
            'notes' => __('note'),
        ] + self::transportAttributes())->validate();

        $date = Carbon::createFromFormat('Y-m-d', $data['dispatch_date'])->startOfDay();
        if ($date->lt($sale->sale_date)) {
            throw ValidationException::withMessages(['dispatch_date' => __('A delivery cannot be dated before its sale (:date).', ['date' => $sale->sale_date->format('d-m-Y')])]);
        }

        $transport = $this->transport($data, $sale->customer);

        try {
            return DB::transaction(function () use ($actor, $sale, $data, $date, $transport, $idempotencyKey): SaleDispatch {
                $this->lockStock();

                /** @var Sale $locked */
                $locked = Sale::query()->with('customer')->whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SaleStatus::Open) {
                    throw ValidationException::withMessages(['lines' => $locked->isCancelled()
                        ? __('This sale was cancelled.')
                        : __('Everything on this sale has been delivered or closed.')]);
                }

                $saleLines = $locked->lines()->with('product.unit')->get()->keyBy('id');
                $items = [];
                foreach (array_values($data['lines']) as $index => $row) {
                    $line = $saleLines->get((int) $row['sale_line_id']);
                    if ($line === null) {
                        throw ValidationException::withMessages(["lines.{$index}.quantity" => __('This is not a line of this sale.')]);
                    }

                    $quantity = $this->quantity($row['quantity'] ?? '', $line->product->unit->decimals, "lines.{$index}.quantity", allowZero: true);
                    if ($quantity->isPositive()) {
                        $items[$index] = ['line' => $line, 'quantity' => $quantity];
                    }
                }

                if ($items === []) {
                    throw ValidationException::withMessages(['lines' => __('Enter how much left with this delivery.')]);
                }

                return $this->deliver($actor, $locked, $date, $items, $transport, $data['notes'] ?? null, $idempotencyKey);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (($existing = $this->existing($idempotencyKey, $sale)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Write a delivery of a locked, open sale. Runs inside the caller's transaction, after the
     * stock lock; the caller has checked the input. Errors point at lines.{index}.quantity.
     *
     * @param  array<int, array{line: SaleLine, quantity: Quantity}>  $items  form index => line and quantity
     * @param  array{driver: Party|null, vehicle: string|null, carriage: Money}  $transport
     */
    public function deliver(User $actor, Sale $sale, CarbonInterface $date, array $items, array $transport, ?string $notes, string $idempotencyKey): SaleDispatch
    {
        if (DB::transactionLevel() === 0) {
            throw new LedgerIntegrityException('Deliveries must be written inside a database transaction.');
        }

        app(BooksLock::class)->assertOpen($date, $actor);

        $delivered = $this->saleStock->delivered(array_map(fn (array $item) => $item['line']->id, array_values($items)));

        foreach ($items as $index => $item) {
            $line = $item['line'];
            $remaining = Quantity::fromMilli($line->quantity->milli - ($delivered[$line->id] ?? 0));
            $decimals = $line->product->unit->decimals;

            if ($item['quantity']->greaterThan($remaining)) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Only :quantity left to deliver.', ['quantity' => $remaining->format($decimals)])]);
            }

            [$stage, $productId] = SaleStock::slot($line->product);
            $lowest = $this->brickStock->lowestFrom($stage, $productId, $date, Quantity::fromMilli(-$item['quantity']->milli));
            if ($lowest->isNegative()) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Not enough :product in stock on :date: this would leave :quantity.', [
                    'product' => $line->product->displayName(),
                    'date' => $date->format('d-m-Y'),
                    'quantity' => $lowest->format($decimals),
                ])]);
            }
        }

        // With carriage, the carriage entry and the delivery share the number (DS-…); without
        // it, the delivery takes the next number of the same sequence.
        $carriageEntry = null;
        if ($transport['carriage']->isPositive() && $transport['driver'] !== null) {
            $carriageEntry = $this->poster->post(JournalType::Carriage, $date, [
                PendingLine::debit($sale->customer->controlAccount(), $transport['carriage'])->forParty($sale->customer),
                PendingLine::credit($transport['driver']->controlAccount(), $transport['carriage'])->forParty($transport['driver']),
            ], $idempotencyKey, $actor);
        }

        $dispatch = SaleDispatch::query()->create([
            'sale_id' => $sale->id,
            'number' => $carriageEntry->number ?? app(DocumentNumbers::class)->next(JournalType::Carriage->prefix(), $date),
            'dispatch_date' => $date->format('Y-m-d'),
            'driver_id' => $transport['driver']?->id,
            'vehicle' => $transport['vehicle'],
            'carriage' => $carriageEntry !== null ? $transport['carriage'] : Money::zero(),
            'carriage_entry_id' => $carriageEntry?->id,
            'notes' => $notes,
            'idempotency_key' => trim($idempotencyKey),
            'created_by' => $actor->id,
        ]);

        foreach ($items as $item) {
            SaleDispatchLine::query()->create([
                'sale_dispatch_id' => $dispatch->id,
                'sale_line_id' => $item['line']->id,
                'quantity' => $item['quantity'],
            ]);

            [$stage, $productId] = SaleStock::slot($item['line']->product);
            BrickMovement::query()->create([
                'sale_dispatch_id' => $dispatch->id,
                'movement_date' => $date->format('Y-m-d'),
                'stage_from' => $stage,
                'stage_to' => null,
                'product_id' => $productId,
                'quantity' => $item['quantity'],
                'created_by' => $actor->id,
            ]);
        }

        // Everything delivered: the sale is complete and reserves nothing any more.
        $lines = $sale->lines()->get();
        $deliveredNow = $this->saleStock->delivered($lines->pluck('id')->all());
        if ($lines->every(fn (SaleLine $line) => ($deliveredNow[$line->id] ?? 0) >= $line->quantity->milli)) {
            $sale->status = SaleStatus::Completed;
            $sale->save();
        }

        return $dispatch;
    }

    /**
     * The driver, vehicle and carriage of a delivery, checked. Carriage needs a driver to owe it to.
     *
     * @param  array<string, mixed>  $data  driver_id, vehicle, carriage (validated with transportRules())
     * @return array{driver: Party|null, vehicle: string|null, carriage: Money}
     */
    public function transport(array $data, Party $customer): array
    {
        $carriage = blank($data['carriage'] ?? null) ? Money::zero() : Money::fromRupees((string) $data['carriage']);

        $driver = null;
        if (filled($data['driver_id'] ?? null)) {
            $driver = $this->partyWithRole($data['driver_id'], PartyRole::Driver, 'driver_id', __('Choose an active party with the driver role.'));

            if ($driver->id === $customer->id) {
                throw ValidationException::withMessages(['driver_id' => __('The driver cannot be the customer of this sale.')]);
            }
        }

        if ($carriage->isPositive() && $driver === null) {
            throw ValidationException::withMessages(['driver_id' => __('Choose the driver who is owed the carriage.')]);
        }

        return ['driver' => $driver, 'vehicle' => $data['vehicle'] ?? null, 'carriage' => $carriage];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function transportRules(): array
    {
        return [
            'driver_id' => ['nullable', 'integer'],
            'vehicle' => ['nullable', 'string', 'max:30'],
            'carriage' => ['nullable', new MoneyAmount(allowZero: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function transportAttributes(): array
    {
        return [
            'driver_id' => __('driver'),
            'vehicle' => __('vehicle'),
            'carriage' => __('carriage'),
        ];
    }

    private function existing(string $idempotencyKey, Sale $sale): ?SaleDispatch
    {
        $dispatch = SaleDispatch::query()->where('idempotency_key', trim($idempotencyKey))->first();

        if ($dispatch !== null && $dispatch->sale_id !== $sale->id) {
            throw new LedgerIntegrityException('This idempotency key was already used for another delivery.');
        }

        return $dispatch;
    }
}
