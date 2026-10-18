<?php

namespace App\Models\Concerns;

use App\Models\Kiln;
use App\Models\Scopes\KilnScope;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\MissingKilnContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model that belongs to one kiln.
 *
 * - Queries only ever see rows of the current kiln.
 * - kiln_id is always taken from the current kiln, never from user input.
 * - Saving a row of another kiln throws, so a bug cannot write across kilns.
 *
 * @mixin Model
 */
trait BelongsToKiln
{
    public static function bootBelongsToKiln(): void
    {
        static::addGlobalScope(new KilnScope);

        static::creating(function (Model $model): void {
            $currentId = app(CurrentKiln::class)->id();

            if ($model->getAttribute('kiln_id') !== null && (int) $model->getAttribute('kiln_id') !== $currentId) {
                throw new MissingKilnContext('Refusing to create a record for a different kiln.');
            }

            $model->setAttribute('kiln_id', $currentId);
        });

        static::updating(function (Model $model): void {
            if ((int) $model->getOriginal('kiln_id') !== app(CurrentKiln::class)->id()
                || $model->isDirty('kiln_id')) {
                throw new MissingKilnContext('Refusing to change a record of a different kiln.');
            }
        });

        static::deleting(function (Model $model): void {
            if ((int) $model->getOriginal('kiln_id') !== app(CurrentKiln::class)->id()) {
                throw new MissingKilnContext('Refusing to delete a record of a different kiln.');
            }
        });
    }

    /**
     * @return BelongsTo<Kiln, $this>
     */
    public function kiln(): BelongsTo
    {
        return $this->belongsTo(Kiln::class);
    }
}
