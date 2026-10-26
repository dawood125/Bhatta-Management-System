<?php

namespace App\Actions\Sales;

use App\Accounting\JournalPoster;
use App\Actions\Sales\Concerns\ChecksSales;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a whole sale by the owner or manager, with a reason, while none of its bricks have
 * been delivered: its invoice entry is reversed and its bricks are no longer reserved. Money
 * received from the customer stays on their account. Once something was delivered, reverse
 * those deliveries first, or close what is left instead (CloseSale).
 */
class CancelSale
{
    use ChecksSales, ValidatesSettings;

    public function __construct(private readonly JournalPoster $poster) {}

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
            $fresh = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                throw ValidationException::withMessages(['reason' => __('This sale has already been cancelled.')]);
            }

            if ($fresh->isClosed()) {
                throw ValidationException::withMessages(['reason' => __('This sale was closed: what was delivered stays sold.')]);
            }

            if ($fresh->dispatches()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['reason' => __('Bricks of this sale were delivered. Reverse those deliveries first, or close what is left.')]);
            }

            $this->poster->reverse($fresh->entry()->firstOrFail(), now(), $data['reason'], substr($fresh->idempotency_key, 0, 56).':cancel', $actor);

            $fresh->forceFill([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $data['reason'],
            ])->save();

            return $fresh;
        });
    }
}
