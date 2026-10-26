<?php

namespace App\Actions\Sales;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Models\LedgerAccount;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Sales\SaleStock;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Close a partly delivered sale by the owner or manager, with a reason: the customer no longer
 * takes the rest. A credit note (CN-…) takes the value of what was not delivered off what the
 * customer owes, and those bricks are no longer reserved. A line's discount is shared between
 * delivered and undelivered bricks in proportion, to the exact paisa.
 */
class CloseSale
{
    use ChecksSales, ValidatesSettings;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly SaleStock $saleStock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, Sale $sale, array $input): Sale
    {
        $this->authorizeSettings($actor, Permission::SalesAdjust);
        $data = Validator::make($this->clean($input, []), [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($actor, $sale, $data): Sale {
            $this->lockStock();

            /** @var Sale $fresh */
            $fresh = Sale::query()->with(['customer', 'lines'])->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== SaleStatus::Open) {
                throw ValidationException::withMessages(['reason' => $fresh->isCancelled()
                    ? __('This sale has already been cancelled.')
                    : __('Everything on this sale has been delivered or closed.')]);
            }

            $delivered = $this->saleStock->delivered($fresh->lines->pluck('id')->all());
            if (array_sum($delivered) === 0) {
                throw ValidationException::withMessages(['reason' => __('Nothing was delivered yet: cancel the sale instead.')]);
            }

            $credit = $this->undeliveredValue($fresh, $delivered);

            $entry = null;
            if ($credit->isPositive()) {
                $entry = $this->poster->post(JournalType::SaleCredit, now(), [
                    PendingLine::debit(LedgerAccount::bySystemKey(LedgerAccount::SALES), $credit),
                    PendingLine::credit($fresh->customer->controlAccount(), $credit)->forParty($fresh->customer),
                ], substr($fresh->idempotency_key, 0, 58).':close', $actor, $data['reason']);
            }

            $fresh->forceFill([
                'status' => SaleStatus::Completed,
                'credit_entry_id' => $entry?->id,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_reason' => $data['reason'],
            ])->save();

            return $fresh;
        });
    }

    /**
     * The value of the bricks not delivered: each line's amount less the delivered share of it.
     *
     * @param  array<int, int>  $delivered  sale line id => thousandths delivered
     */
    public function undeliveredValue(Sale $sale, array $delivered): Money
    {
        $paisa = 0;
        foreach ($sale->lines as $line) {
            /** @var SaleLine $line */
            $deliveredShare = BigDecimal::of($line->amount->paisa)
                ->multipliedBy($delivered[$line->id] ?? 0)
                ->dividedBy($line->quantity->milli, 0, RoundingMode::HalfUp)
                ->toInt();

            $paisa += $line->amount->paisa - $deliveredShare;
        }

        return Money::fromPaisa($paisa);
    }
}
