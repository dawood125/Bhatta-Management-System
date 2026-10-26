<?php

namespace App\Livewire\Sales;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\CloseSale;
use App\Actions\Sales\EmailSaleInvoice;
use App\Actions\Sales\ReceiveSalePayment;
use App\Actions\Sales\RecordDispatch;
use App\Actions\Sales\ReverseDispatch;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\EmailLog;
use App\Models\Party;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\SaleLine;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Sales\CustomerPayments;
use App\Sales\SaleStock;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One sale: what was ordered, delivered and is still to come, each delivery, what the customer
 * has paid, and the invoice (PDF, email). Deliveries, payments and corrections are recorded here.
 *
 * @property-read array<int, int> $delivered Livewire computed property
 * @property-read Collection<int, TreasuryAccount> $accounts Livewire computed property
 * @property-read Collection<int, Party> $drivers Livewire computed property
 */
class SaleShow extends Component
{
    use RunsActions;

    public Sale $sale;

    public bool $showDelivery = false;

    public bool $showPayment = false;

    public bool $showReason = false;

    /** What the reason dialog does: reverse (a delivery) | cancel | close. Set by the open methods only. */
    #[Locked]
    public string $reasonFor = '';

    #[Locked]
    public ?int $reversingId = null;

    /** @var array<string, mixed> */
    public array $deliveryForm = [];

    /** @var array<string, string> */
    public array $paymentForm = [];

    /** @var array<string, string> */
    public array $reasonForm = ['reason' => ''];

    public string $formKey = '';

    public function mount(Sale $sale): void
    {
        $this->authorize(Permission::SalesView->value);
        $this->sale = $sale;
    }

    /**
     * Delivered so far per sale line (thousandths).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function delivered(): array
    {
        return app(SaleStock::class)->delivered($this->sale->lines->pluck('id')->all());
    }

    /**
     * @return Collection<int, TreasuryAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return TreasuryAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
    }

    /**
     * @return Collection<int, Party>
     */
    #[Computed]
    public function drivers(): Collection
    {
        return Party::query()->withRole(PartyRole::Driver)->where('is_active', true)->orderBy('name')->get();
    }

    public function openDelivery(): void
    {
        $this->authorize(Permission::SalesRecord->value);
        $this->resetErrorBag();

        $lines = [];
        foreach ($this->sale->lines as $line) {
            /** @var SaleLine $line */
            $remaining = Quantity::fromMilli($line->quantity->milli - ($this->delivered[$line->id] ?? 0));
            $lines[] = ['sale_line_id' => (string) $line->id, 'quantity' => $remaining->isPositive() ? $remaining->toInput($line->product->unit->decimals) : ''];
        }

        $this->deliveryForm = [
            'dispatch_date' => now()->format('Y-m-d'),
            'driver_id' => '',
            'vehicle' => '',
            'carriage' => '',
            'notes' => '',
            'lines' => $lines,
        ];
        $this->formKey = (string) Str::uuid();
        $this->showDelivery = true;
    }

    public function saveDelivery(RecordDispatch $recordDispatch): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $dispatch = $this->runAction(fn () => $recordDispatch->handle($actor, $this->sale, $this->deliveryForm, $this->formKey), 'deliveryForm');

        $this->showDelivery = false;
        $this->refreshSale();
        $this->toast(__(':number recorded.', ['number' => $dispatch->number]));
    }

    public function openPayment(): void
    {
        $this->authorize(Permission::PaymentsRecord->value);
        $this->resetErrorBag();

        $outstanding = app(CustomerPayments::class)->forSale($this->sale)['outstanding'];
        $this->paymentForm = [
            'entry_date' => now()->format('Y-m-d'),
            'amount' => $outstanding->isPositive() ? $outstanding->toDecimal() : '',
            'account_id' => (string) ($this->accounts->first()->id ?? ''),
            'memo' => '',
        ];
        $this->formKey = (string) Str::uuid();
        $this->showPayment = true;
    }

    /** A receipt from the customer for this sale: it pays this sale first. */
    public function savePayment(ReceiveSalePayment $receivePayment): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $entry = $this->runAction(fn () => $receivePayment->handle($actor, $this->sale, $this->paymentForm, $this->formKey), 'paymentForm');

        $this->showPayment = false;
        $this->toast(__(':number recorded.', ['number' => $entry->number]));
    }

    public function confirmReverse(int $dispatchId): void
    {
        $this->authorize(Permission::SalesAdjust->value);
        $this->openReason('reverse');
        $this->reversingId = $dispatchId;
    }

    public function confirmCancel(): void
    {
        $this->authorize(Permission::SalesAdjust->value);
        $this->openReason('cancel');
    }

    public function confirmClose(): void
    {
        $this->authorize(Permission::SalesAdjust->value);
        $this->openReason('close');
    }

    public function saveReason(ReverseDispatch $reverseDispatch, CancelSale $cancelSale, CloseSale $closeSale): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $message = match ($this->reasonFor) {
            'reverse' => $this->runAction(function () use ($reverseDispatch, $actor) {
                $dispatch = SaleDispatch::query()->where('sale_id', $this->sale->id)->findOrFail($this->reversingId);

                return __(':number reversed.', ['number' => $reverseDispatch->handle($actor, $dispatch, $this->reasonForm)->number]);
            }, 'reasonForm'),
            'cancel' => $this->runAction(fn () => __(':number cancelled.', ['number' => $cancelSale->handle($actor, $this->sale, $this->reasonForm)->number]), 'reasonForm'),
            'close' => $this->runAction(fn () => __(':number closed.', ['number' => $closeSale->handle($actor, $this->sale, $this->reasonForm)->number]), 'reasonForm'),
            default => null,
        };

        $this->showReason = false;
        $this->reversingId = null;
        $this->refreshSale();
        if ($message !== null) {
            $this->toast($message);
        }
    }

    public function emailInvoice(EmailSaleInvoice $emailInvoice): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $log = $this->runAction(fn () => $emailInvoice->handle($actor, $this->sale));

        $this->toast(__('The invoice will be emailed to :to.', ['to' => $log->recipients]));
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    public function render(): View
    {
        $this->sale->loadMissing(['customer', 'agent', 'creator', 'closer', 'canceller', 'lines.product.unit', 'dispatches.driver', 'dispatches.lines', 'dispatches.reverser', 'receiptEntry', 'receipts.entry', 'creditEntry']);

        /** @var User $actor */
        $actor = auth()->user();
        $open = $this->sale->status === SaleStatus::Open;
        $liveDispatches = $this->sale->dispatches->reject(fn (SaleDispatch $dispatch) => $dispatch->isReversed());
        $payment = app(CustomerPayments::class)->forSale($this->sale);

        return view('livewire.sales.sale-show', [
            'payment' => $payment,
            'carriage' => Money::sum($liveDispatches->map(fn (SaleDispatch $dispatch) => $dispatch->carriage)),
            'balance' => $this->sale->customer->balance(),
            'emails' => EmailLog::query()->where('sale_id', $this->sale->id)->latest('id')->limit(10)->get(),
            'canDeliver' => $open && $actor->can(Permission::SalesRecord->value),
            'canReceive' => ! $this->sale->isCancelled() && $actor->can(Permission::PaymentsRecord->value),
            'canEmail' => ! $this->sale->isCancelled() && $actor->can(Permission::SalesRecord->value),
            'canAdjust' => $actor->can(Permission::SalesAdjust->value),
            'canCancel' => ! $this->sale->isCancelled() && ! $this->sale->isClosed() && $liveDispatches->isEmpty(),
            'canClose' => $open && $liveDispatches->isNotEmpty(),
        ])->title(__('Sale').' '.$this->sale->number);
    }

    private function openReason(string $for): void
    {
        $this->resetErrorBag();
        $this->reasonFor = $for;
        $this->reversingId = null;
        $this->reasonForm = ['reason' => ''];
        $this->showReason = true;
    }

    private function refreshSale(): void
    {
        $this->sale = $this->sale->fresh() ?? $this->sale;
        unset($this->delivered);
    }
}
