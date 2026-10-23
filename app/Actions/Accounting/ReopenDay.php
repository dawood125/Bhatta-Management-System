<?php

namespace App\Actions\Accounting;

use App\Accounting\BooksLock;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\DayClose;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reopen the last closed day (owner only, with a reason): entries can be dated on it again.
 * The close stays in the history with who reopened it and why; the lock moves back to the
 * day closed before it.
 */
class ReopenDay
{
    use ValidatesSettings;

    public function __construct(private readonly BooksLock $booksLock) {}

    /**
     * @param  array<string, mixed>  $input  reason
     */
    public function handle(User $actor, DayClose $close, array $input): DayClose
    {
        $this->authorizeSettings($actor, Permission::DaysReopen);
        $data = Validator::make($this->clean($input, []), [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($actor, $close, $data): DayClose {
            /** @var DayClose $fresh */
            $fresh = DayClose::query()->whereKey($close->id)->lockForUpdate()->firstOrFail();
            $through = $this->booksLock->closedThrough();

            if ($fresh->isReopened() || $through === null || ! $fresh->close_date->isSameDay($through)) {
                throw ValidationException::withMessages(['reason' => __('Only the last closed day can be reopened.')]);
            }

            $fresh->forceFill([
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
                'reopen_reason' => $data['reason'],
            ])->save();

            return $fresh;
        });
    }
}
