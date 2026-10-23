<?php

namespace App\Models;

use App\Accounting\LedgerQueries;
use App\Casts\MoneyCast;
use App\Enums\TreasuryKind;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A place where the kiln keeps money: cash in hand, a bank account, a mobile wallet, cash held
 * by a person, or a credit card. Its balance always comes from the journal.
 *
 * @property int $id
 * @property int $kiln_id
 * @property int $ledger_account_id
 * @property TreasuryKind $kind
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $account_number
 * @property Money $opening_balance
 * @property Carbon $opening_date
 * @property int|null $opening_entry_id
 * @property bool $allow_negative
 * @property bool $is_active
 * @property int $sort_order
 * @property-read LedgerAccount $ledgerAccount
 */
#[Fillable(['ledger_account_id', 'kind', 'name', 'name_ur', 'account_number', 'opening_balance', 'opening_date', 'opening_entry_id', 'allow_negative', 'is_active', 'sort_order'])]
class TreasuryAccount extends Model
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'kind' => TreasuryKind::class,
            'opening_balance' => MoneyCast::class,
            'opening_date' => 'date',
            'allow_negative' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** Current balance in the account's natural direction (money held, or owed for a card). */
    public function balance(?Carbon $asOf = null): Money
    {
        return app(LedgerQueries::class)->balance($this->ledgerAccount, $asOf);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'name', 'name_ur', 'account_number', 'opening_date', 'allow_negative', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
