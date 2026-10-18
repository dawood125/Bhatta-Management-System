<?php

namespace Tests\Fixtures;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToKiln;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal kiln-owned model for tests.
 *
 * @property int $kiln_id
 * @property string $name
 * @property Money|null $amount
 */
class KilnRecord extends Model
{
    use BelongsToKiln;

    protected $table = 'test_kiln_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }
}
