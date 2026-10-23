<?php

namespace App\Actions\Sales;

use App\Accounting\BooksLock;
use App\Accounting\JournalPoster;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Models\BrickMovement;
use App\Models\Sale;
use App\Models\SaleDispatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a delivery by the owner or manager, with a reason: the bricks go back into stock
 * (mirror movements dated today), the carriage is reversed, and the bricks count as still to be
 * delivered on the sale again. This is also how returned bricks are recorded: reverse the
 * delivery, then close what the customer no longer takes. Refused once the sale was closed.
 */
class ReverseDispatch
{
    use ChecksSales, ValidatesSettings;

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, SaleDispatch $dispatch, array $input): SaleDispatch
    {
        $this->authorizeSettings($actor, Permission::SalesAdjust);
        $data = Validator::make($this->clean($input, []), [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($actor, $dispatch, $data): SaleDispatch {
            $this->lockStock();

            /** @var SaleDispatch $fresh */
            $fresh = SaleDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            /** @var Sale $sale */
            $sale = Sale::query()->whereKey($fresh->sale_id)->lockForUpdate()->firstOrFail();

            if ($fresh->isReversed()) {
                throw ValidationException::withMessages(['reason' => __('This delivery has already been reversed.')]);
            }

            if ($sale->isClosed()) {
                throw ValidationException::withMessages(['reason' => __('This sale was closed, so its deliveries can no longer be reversed.')]);
            }

            $today = now()->startOfDay();
            app(BooksLock::class)->assertOpen($today, $actor);
            foreach ($fresh->movements()->get() as $movement) {
                BrickMovement::query()->create([
                    'sale_dispatch_id' => $fresh->id,
                    'movement_date' => $today->format('Y-m-d'),
                    'stage_from' => $movement->stage_to,
                    'stage_to' => $movement->stage_from,
                    'product_id' => $movement->product_id,
                    'quantity' => $movement->quantity,
                    'reversal_of_id' => $movement->id,
                    'created_by' => $actor->id,
                ]);
            }

            // The driver is owed the carriage no more (one already paid then owes it back).
            if ($fresh->carriage_entry_id !== null) {
                $entry = $fresh->carriageEntry()->firstOrFail();
                if (! $entry->isReversed()) {
                    $this->poster->reverse($entry, now(), $data['reason'], substr($fresh->idempotency_key, 0, 56).':rev', $actor);
                }
            }

            $fresh->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $data['reason'],
            ])->save();

            if ($sale->status === SaleStatus::Completed) {
                $sale->status = SaleStatus::Open;
                $sale->save();
            }

            return $fresh;
        });
    }
}
