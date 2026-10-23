<?php

namespace App\Models;

use App\Models\Concerns\BelongsToKiln;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One email the system sent (or tried to send), e.g. a sale's invoice to the owner: who it went
 * to, how many attempts it took and the last error. Each "resend" is a new row.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int|null $sale_id
 * @property string $kind
 * @property string $recipients
 * @property string $subject
 * @property string $locale
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Sale|null $sale
 * @property-read User|null $creator
 */
class EmailLog extends Model
{
    use BelongsToKiln;

    public const KIND_SALE_INVOICE = 'sale_invoice';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** How many times the queue tries an email before giving up (the log then shows "failed"). */
    public const MAX_ATTEMPTS = 3;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public function recipientList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->recipients))));
    }

    /** Failed but the queue will try again. */
    public function willRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_SENT => __('Sent'),
            $this->willRetry() => __('Failed, will retry'),
            $this->status === self::STATUS_FAILED => __('Failed'),
            default => __('Waiting to send'),
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->status === self::STATUS_SENT => 'success',
            $this->willRetry() => 'warning',
            $this->status === self::STATUS_FAILED => 'danger',
            default => 'neutral',
        };
    }
}
