<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An account in the kiln's chart of accounts.
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $code
 * @property string $name
 * @property string|null $name_ur
 * @property AccountType $type
 * @property string|null $system_key
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'name_ur', 'type', 'system_key', 'is_active'])]
class LedgerAccount extends Model
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    public const OWNER_CAPITAL = 'owner_capital';

    public const OWNER_DRAWINGS = 'owner_drawings';

    public const OPENING_BALANCE_EQUITY = 'opening_balance_equity';

    public const RECEIVABLES = 'receivables';

    public const PAYABLES = 'payables';

    public const MATERIAL_STOCK = 'material_stock';

    public const MATERIAL_SHORTAGES = 'material_shortages';

    /** Accounts whose lines must name a party (customer/supplier sub-ledgers). */
    public const PARTY_ACCOUNTS = [self::RECEIVABLES, self::PAYABLES];

    public function requiresParty(): bool
    {
        return in_array($this->system_key, self::PARTY_ACCOUNTS, true);
    }

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return HasOne<TreasuryAccount, $this>
     */
    public function treasuryAccount(): HasOne
    {
        return $this->hasOne(TreasuryAccount::class);
    }

    public static function bySystemKey(string $key): self
    {
        return self::query()->where('system_key', $key)->firstOrFail();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'name_ur', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
