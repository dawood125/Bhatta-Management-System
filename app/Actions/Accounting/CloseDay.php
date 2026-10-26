<?php

namespace App\Actions\Accounting;

use App\Accounting\BooksLock;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\DayClose;
use App\Models\DayCloseCount;
use App\Models\Kiln;
use App\Models\User;
use App\Reports\CashDay;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Close a day (proposed default for Q-41): every cash/bank account's day is saved with what was
 * counted, if it was, and the day (with every day before it) is closed. A difference between the
 * count and the books is shown, not posted: the owner decides what it was (e.g. record a missing
 * expense). Only the owner can then add entries on or before the day, or reopen it.
 */
class CloseDay
{
    use ValidatesSettings;

    public function __construct(
        private readonly CashDay $cashDay,
        private readonly BooksLock $booksLock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  close_date, notes, counts[treasury_account_id] = amount counted ('' = not counted)
     */
    public function handle(User $actor, array $input): DayClose
    {
        $this->authorizeSettings($actor, Permission::DaysClose);

        $counts = is_array($input['counts'] ?? null) ? $input['counts'] : [];
        $input = $this->clean(array_diff_key($input, ['counts' => true]), ['notes']);
        $input['counts'] = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $counts);

        $data = Validator::make($input, [
            'close_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'counts' => ['array'],
            'counts.*' => ['nullable', new MoneyAmount(allowZero: true)],
        ], [], [
            'close_date' => __('date'),
            'notes' => __('note'),
            'counts.*' => __('amount counted'),
        ])->validate();

        $date = Carbon::createFromFormat('Y-m-d', $data['close_date'])->startOfDay();

        return DB::transaction(function () use ($actor, $data, $date): DayClose {
            // One close at a time per kiln.
            Kiln::query()->whereKey($this->kilnId())->lockForUpdate()->first();

            $through = $this->booksLock->closedThrough();
            if ($through !== null && $date->lte($through)) {
                throw ValidationException::withMessages(['close_date' => __('The books are already closed up to :date.', ['date' => $through->format('d-m-Y')])]);
            }

            $close = DayClose::query()->create([
                'close_date' => $date->format('Y-m-d'),
                'notes' => $data['notes'] ?? null,
                'closed_by' => $actor->id,
            ]);

            foreach ($this->cashDay->forDate($date) as $row) {
                $counted = $data['counts'][$row['account']->id] ?? null;

                DayCloseCount::query()->create([
                    'day_close_id' => $close->id,
                    'treasury_account_id' => $row['account']->id,
                    'opening' => $row['opening'],
                    'money_in' => $row['in'],
                    'money_out' => $row['out'],
                    'expected' => $row['closing'],
                    'counted' => blank($counted) ? null : Money::fromRupees((string) $counted),
                ]);
            }

            return $close;
        });
    }
}
