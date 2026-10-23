<?php

namespace App\Models;

use App\Accounting\LedgerQueries;
use App\Casts\MoneyCast;
use App\Enums\PartyRole;
use App\Models\Concerns\BelongsToKiln;
use App\Models\Concerns\HasLocalizedName;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A customer, supplier, driver or sales agent. Its balance always comes from the journal:
 * positive means they owe us (receivable), negative means we owe them (payable).
 *
 * @property int $id
 * @property int $kiln_id
 * @property string $name
 * @property string|null $name_ur
 * @property string|null $phone
 * @property string|null $phone_2
 * @property string|null $address
 * @property int|null $area_id
 * @property int|null $sales_agent_id
 * @property string|null $notes
 * @property Money $opening_balance
 * @property Carbon $opening_date
 * @property int|null $opening_entry_id
 * @property bool $is_active
 * @property-read Area|null $area
 * @property-read Party|null $salesAgent
 */
#[Fillable(['name', 'name_ur', 'phone', 'phone_2', 'address', 'area_id', 'sales_agent_id', 'notes', 'opening_balance', 'opening_date', 'opening_entry_id', 'is_active', 'creation_key'])]
class Party extends Model
{
    use BelongsToKiln, HasLocalizedName, LogsActivity;

    protected function casts(): array
    {
        return [
            'opening_balance' => MoneyCast::class,
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'sales_agent_id');
    }

    /**
     * @return list<PartyRole>
     */
    public function roles(): array
    {
        return DB::table('party_roles')
            ->where('party_id', $this->id)
            ->orderBy('role')
            ->pluck('role')
            ->map(fn (string $role) => PartyRole::from($role))
            ->all();
    }

    public function hasRole(PartyRole $role): bool
    {
        return DB::table('party_roles')->where('party_id', $this->id)->where('role', $role->value)->exists();
    }

    /**
     * @param  list<PartyRole>  $roles
     */
    public function syncRoles(array $roles): void
    {
        $values = array_values(array_unique(array_map(fn (PartyRole $role) => $role->value, $roles)));

        DB::table('party_roles')->where('party_id', $this->id)->whereNotIn('role', $values)->delete();

        foreach ($values as $value) {
            DB::table('party_roles')->insertOrIgnore([
                'party_id' => $this->id,
                'role' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  Builder<Party>  $query
     */
    public function scopeWithRole(Builder $query, PartyRole $role): void
    {
        $query->whereExists(fn ($q) => $q->from('party_roles')
            ->whereColumn('party_roles.party_id', 'parties.id')
            ->where('party_roles.role', $role->value));
    }

    /** Net balance: positive = they owe us, negative = we owe them. */
    public function balance(?Carbon $asOf = null): Money
    {
        return app(LedgerQueries::class)->partyBalances([$this->id], $asOf)[$this->id];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_ur', 'phone', 'phone_2', 'address', 'area_id', 'sales_agent_id', 'opening_date', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
