<?php

namespace App\Actions\Parties;

use App\Accounting\JournalPoster;
use App\Accounting\PendingLine;
use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\JournalType;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a customer / supplier / driver / sales agent.
 *
 * The opening balance ("they owe us" or "we owe them") is posted to the party's control account
 * (receivables for customers, payables for others) against "Opening balances". Changing it later reverses the old opening entry on its own date
 * and posts a new one, exactly like cash/bank accounts.
 */
class SaveParty
{
    use ValidatesSettings;

    public const THEY_OWE_US = 'receivable';

    public const WE_OWE_THEM = 'payable';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * @param  array<string, mixed>  $input  roles[], name, name_ur, phone, phone_2, address, area_id,
     *                                       sales_agent_id, notes, opening_amount, opening_direction, opening_date
     */
    public function handle(User $actor, ?Party $party, array $input, string $idempotencyKey): Party
    {
        $this->authorizeSettings($actor, Permission::PartiesManage);

        // The same "Add party" form submitted twice: return the party it already created.
        if ($party === null && ($existing = Party::query()->where('creation_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }
        $input = $this->clean($input, ['name_ur', 'phone', 'phone_2', 'address', 'notes', 'area_id', 'sales_agent_id']);
        $kilnId = $this->kilnId();

        $data = Validator::make($input, [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::enum(PartyRole::class)],
            'name' => ['required', 'string', 'max:150'],
            'name_ur' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'phone_2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->where('kiln_id', $kilnId)],
            'sales_agent_id' => [
                'nullable', 'integer',
                Rule::exists('parties', 'id')->where('kiln_id', $kilnId)->where('is_active', true),
                Rule::notIn(array_filter([$party?->id])),
            ],
            'opening_amount' => ['required', new MoneyAmount(allowZero: true)],
            'opening_direction' => ['required', Rule::in([self::THEY_OWE_US, self::WE_OWE_THEM])],
            'opening_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'roles.required' => __('Choose at least one: customer, supplier, driver or sales agent.'),
            'roles.min' => __('Choose at least one: customer, supplier, driver or sales agent.'),
            'sales_agent_id.not_in' => __('A party cannot be its own sales agent.'),
        ])->validate();

        $roles = array_map(fn (string $role) => PartyRole::from($role), array_values(array_unique($data['roles'])));
        $isCustomer = in_array(PartyRole::Customer, $roles, true);

        // Only customers have a sales agent, and the agent must really be a sales agent.
        $agentId = $isCustomer && $data['sales_agent_id'] !== null ? (int) $data['sales_agent_id'] : null;
        if ($agentId !== null && ! Party::query()->whereKey($agentId)->withRole(PartyRole::Agent)->exists()) {
            throw ValidationException::withMessages(['sales_agent_id' => __('Choose a party that is a sales agent.')]);
        }

        $amount = Money::fromRupees($data['opening_amount']);
        $opening = $data['opening_direction'] === self::WE_OWE_THEM ? $amount->negate() : $amount;
        $openingDate = Carbon::createFromFormat('Y-m-d', $data['opening_date'])->startOfDay();

        try {
            return $this->save($actor, $party, $data, $roles, $agentId, $opening, $openingDate, $idempotencyKey);
        } catch (UniqueConstraintViolationException $e) {
            // Two identical "Add party" requests raced each other: return the one that won.
            if ($party === null && ($existing = Party::query()->where('creation_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<PartyRole>  $roles
     */
    private function save(User $actor, ?Party $party, array $data, array $roles, ?int $agentId, Money $opening, Carbon $openingDate, string $idempotencyKey): Party
    {
        return DB::transaction(function () use ($actor, $party, $data, $roles, $agentId, $opening, $openingDate, $idempotencyKey): Party {
            $attributes = [
                'name' => $data['name'],
                'name_ur' => $data['name_ur'],
                'phone' => $data['phone'],
                'phone_2' => $data['phone_2'],
                'address' => $data['address'],
                'notes' => $data['notes'],
                'area_id' => $data['area_id'] !== null ? (int) $data['area_id'] : null,
                'sales_agent_id' => $agentId,
            ];

            if ($party === null) {
                $party = Party::query()->create($attributes + [
                    'opening_balance' => $opening,
                    'opening_date' => $openingDate->format('Y-m-d'),
                    'is_active' => true,
                    'creation_key' => $idempotencyKey,
                ]);
                $party->syncRoles($roles);
                $this->logRoles($actor, $party, [], $roles);

                if (! $opening->isZero()) {
                    $party->opening_entry_id = $this->postOpening($actor, $party, $opening, $openingDate, $idempotencyKey.':open')->id;
                    $party->save();
                }

                return $party;
            }

            /** @var Party $party */
            $party = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            $oldRoles = $party->roles();

            if (in_array(PartyRole::Agent, $oldRoles, true) && ! in_array(PartyRole::Agent, $roles, true)) {
                $customers = Party::query()->where('sales_agent_id', $party->id)->count();
                if ($customers > 0) {
                    throw ValidationException::withMessages([
                        'roles' => __('This party is the sales agent of :count customer(s). Change those customers first.', ['count' => $customers]),
                    ]);
                }
            }

            $party->fill($attributes)->save();
            $party->syncRoles($roles);
            $this->logRoles($actor, $party, $oldRoles, $roles);

            $openingChanged = ! $party->opening_balance->equals($opening)
                || $party->opening_date->format('Y-m-d') !== $openingDate->format('Y-m-d');

            if ($openingChanged) {
                $this->assertNoEntriesBefore($party, $openingDate);

                if ($party->opening_entry_id !== null) {
                    $old = JournalEntry::query()->findOrFail($party->opening_entry_id);
                    // Dated like the original so the two cancel out at the same point in history.
                    $this->poster->reverse($old, $old->entry_date, 'Opening balance changed', $idempotencyKey.':rev', $actor);
                }

                $party->opening_balance = $opening;
                $party->opening_date = $openingDate;
                $party->opening_entry_id = $opening->isZero()
                    ? null
                    : $this->postOpening($actor, $party, $opening, $openingDate, $idempotencyKey.':open')->id;
                $party->save();
            }

            return $party;
        });
    }

    private function postOpening(User $actor, Party $party, Money $opening, Carbon $date, string $key): JournalEntry
    {
        $equity = LedgerAccount::bySystemKey(LedgerAccount::OPENING_BALANCE_EQUITY);
        $control = $party->controlAccount();
        $amount = $opening->abs();

        $lines = $opening->isPositive()
            // They owe us: debit the party.
            ? [PendingLine::debit($control, $amount)->forParty($party), PendingLine::credit($equity, $amount)]
            // We owe them: credit the party.
            : [PendingLine::debit($equity, $amount), PendingLine::credit($control, $amount)->forParty($party)];

        return $this->poster->post(JournalType::OpeningBalance, $date, $lines, $key, $actor);
    }

    /** Transactions dated before the opening date would make old statements wrong. */
    private function assertNoEntriesBefore(Party $party, Carbon $openingDate): void
    {
        $earlier = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.party_id', $party->id)
            ->whereNotIn('journal_entries.type', [JournalType::OpeningBalance->value, JournalType::Reversal->value])
            ->where('journal_entries.entry_date', '<', $openingDate->format('Y-m-d'))
            ->exists();

        if ($earlier) {
            throw ValidationException::withMessages([
                'opening_date' => __('This party has transactions before this date. Choose an earlier opening date.'),
            ]);
        }
    }

    /**
     * @param  list<PartyRole>  $old
     * @param  list<PartyRole>  $new
     */
    private function logRoles(User $actor, Party $party, array $old, array $new): void
    {
        $oldValues = array_map(fn (PartyRole $r) => $r->value, $old);
        $newValues = array_map(fn (PartyRole $r) => $r->value, $new);
        sort($oldValues);
        sort($newValues);

        if ($oldValues === $newValues) {
            return;
        }

        activity()
            ->performedOn($party)
            ->causedBy($actor)
            ->event('roles_changed')
            ->withProperties(['old' => ['roles' => implode(', ', $oldValues)], 'attributes' => ['roles' => implode(', ', $newValues)]])
            ->log('roles_changed');
    }
}
