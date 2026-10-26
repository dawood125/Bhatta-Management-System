<?php

namespace App\Actions\Sales;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Actions\Vouchers\RecordVoucher;
use App\Enums\JournalType;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money received for one sale, from its page: a receipt voucher (RCV-…) from the customer that
 * pays this sale first; anything more stays on the customer's account and pays their other
 * sales, oldest first.
 */
class ReceiveSalePayment
{
    use ValidatesSettings;

    public function __construct(private readonly RecordVoucher $recordVoucher) {}

    /**
     * @param  array<string, mixed>  $input  entry_date, amount, account_id, memo
     */
    public function handle(User $actor, Sale $sale, array $input, string $idempotencyKey): JournalEntry
    {
        $this->authorizeSettings($actor, Permission::PaymentsRecord);

        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['amount' => __('This sale was cancelled.')]);
        }

        try {
            return DB::transaction(fn (): JournalEntry => $this->record($actor, $sale, $input, $idempotencyKey));
        } catch (ValidationException $e) {
            // The voucher checks "lines.0.amount" and "lines.0.memo": report them as this form's fields.
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $messages[match (true) {
                    str_starts_with($key, 'lines.0.') => substr($key, 8),
                    $key === 'lines' => 'amount',
                    default => $key,
                }] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function record(User $actor, Sale $sale, array $input, string $idempotencyKey): JournalEntry
    {
        $entry = $this->recordVoucher->handle($actor, JournalType::Receipt, [
            'account_id' => $input['account_id'] ?? '',
            'entry_date' => $input['entry_date'] ?? '',
            'memo' => __('Payment for :number', ['number' => $sale->number]),
            'lines' => [[
                'type' => RecordVoucher::LINE_PARTY,
                'party_id' => (string) $sale->customer_id,
                'account_id' => '',
                'amount' => $input['amount'] ?? '',
                'memo' => $input['memo'] ?? '',
            ]],
        ], $idempotencyKey);

        SaleReceipt::query()->firstOrCreate(['journal_entry_id' => $entry->id], [
            'sale_id' => $sale->id,
            'created_by' => $actor->id,
        ]);

        return $entry;
    }
}
