<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A backup that was made (nightly or by the owner), or a restore drill, and how it went.
 *
 * @property int $id
 * @property string $kind
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $message
 * @property array<string, mixed>|null $details
 * @property int|null $user_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class BackupRun extends Model
{
    public const SCHEDULED = 'scheduled';

    public const MANUAL = 'manual';

    public const DRILL = 'drill';

    /** A copy of a backup sent to Google Drive. */
    public const COPY = 'copy';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
