<?php

namespace App\Data;

use App\Backups\BackupRunner;
use App\Enums\Role;
use App\Models\BackupRun;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Wipes the current kiln's data, by its owner only, after a backup (client, 2026-11-25).
 *
 * - Clear entries: every transaction goes (sales, production, purchases, usage, payments,
 *   wages, settlements, attendance, trolley costs, expenses, day closes, the journal) and every
 *   opening balance is set to 0, so all balances and stock are 0. Settings, products,
 *   materials, rates, users and the lists of parties, workers, trolleys and accounts stay.
 * - Factory reset: everything of this kiln goes except its profile and the owner's login; other
 *   users lose access to it; the default settings are created again, like a fresh install.
 *
 * Only these two, because the books are double-entry: deleting just one part (say sales but not
 * their payments) would leave entries pointing at nothing. Deletes run as plain queries in
 * foreign-key order (the models refuse to delete posted records on purpose). The activity log
 * keeps its history and gets a line for the reset.
 */
class DataReset
{
    public const CLEAR_ENTRIES = 'clear_entries';

    public const FACTORY = 'factory';

    /** What the owner types to confirm. */
    public const PHRASE = 'RESET ALL DATA';

    /**
     * Transaction tables, children first. Self-references are cleared before deleting.
     *
     * @var list<string>
     */
    private const ENTRY_TABLES = [
        'email_logs',
        'sale_dispatch_lines',
        'brick_movements',
        'work_entries',
        'production_entries',
        'sale_dispatches',
        'sale_receipts',
        'sale_lines',
        'sales',
        'material_movements',
        'material_adjustments',
        'material_purchase_charges',
        'material_purchase_lines',
        'material_purchases',
        'material_usages',
        'settlement_lines',
        'settlements',
        'attendances',
        'trolley_costs',
        'expenses',
        'day_close_counts',
        'day_closes',
        'journal_lines',
        'journal_entries',
        'number_sequences',
    ];

    /**
     * Lists and settings, children first (factory reset only).
     *
     * @var list<string>
     */
    private const SETUP_TABLES = [
        'trolleys',
        'labour_rates',
        'workers',
        'parties',
        'areas',
        'treasury_accounts',
        'expense_sub_categories',
        'expense_categories',
        'labour_categories',
        'materials',
        'products',
        'production_stages',
        'units',
        'ledger_accounts',
    ];

    public function __construct(
        private readonly CurrentKiln $currentKiln,
        private readonly KilnAccess $access,
        private readonly BackupRunner $backups,
        private readonly KilnDefaults $defaults,
    ) {}

    /**
     * @param  array<string, mixed>  $input  mode, password, phrase
     * @return array{backup: BackupRun, mode: string, deleted: int}
     */
    public function handle(User $actor, array $input): array
    {
        $kiln = $this->currentKiln->get();

        if ($this->access->roleIn($actor, $kiln) !== Role::Owner) {
            abort(403);
        }

        $mode = (string) ($input['mode'] ?? '');
        if (! in_array($mode, [self::CLEAR_ENTRIES, self::FACTORY], true)) {
            throw ValidationException::withMessages(['mode' => __('Choose what to reset.')]);
        }

        if (! Hash::check((string) ($input['password'] ?? ''), $actor->password)) {
            throw ValidationException::withMessages(['password' => __('The password is not correct.')]);
        }

        if (strtoupper(trim((string) ($input['phrase'] ?? ''))) !== self::PHRASE) {
            throw ValidationException::withMessages(['phrase' => __('Type :phrase exactly to confirm.', ['phrase' => self::PHRASE])]);
        }

        // A backup first: nothing is deleted unless it succeeded.
        $backup = $this->backups->run($actor);
        if (! $backup->succeeded()) {
            throw ValidationException::withMessages(['mode' => __('The safety backup failed, so nothing was deleted: :message', ['message' => (string) $backup->message])]);
        }

        $deleted = DB::transaction(fn (): int => $mode === self::FACTORY
            ? $this->factoryReset($kiln, $actor)
            : $this->clearEntries($kiln));

        if ($mode === self::FACTORY) {
            $this->defaults->seed($kiln);
        }

        $this->logOutOthers($kiln, $actor);

        activity()
            ->causedBy($actor)
            ->event('data_reset')
            ->withProperties(['attributes' => ['mode' => $mode, 'backup' => $backup->path, 'rows' => $deleted]])
            ->log('data_reset');

        return ['backup' => $backup, 'mode' => $mode, 'deleted' => $deleted];
    }

    /** Every transaction of the kiln, and the opening balances, go. */
    private function clearEntries(Kiln $kiln): int
    {
        $id = $kiln->id;

        // Opening balances are journal entries: forget them first.
        DB::table('parties')->where('kiln_id', $id)->update(['opening_balance' => 0, 'opening_entry_id' => null]);
        DB::table('treasury_accounts')->where('kiln_id', $id)->update(['opening_balance' => 0, 'opening_entry_id' => null]);
        DB::table('workers')->where('kiln_id', $id)->update(['opening_balance' => 0, 'opening_date' => null, 'opening_entry_id' => null]);

        // Rows that point at other rows of the same table.
        DB::table('brick_movements')->where('kiln_id', $id)->update(['reversal_of_id' => null]);
        DB::table('material_movements')->where('kiln_id', $id)->update(['reversal_of_id' => null]);
        DB::table('journal_entries')->where('kiln_id', $id)->update(['reversal_of_id' => null]);

        $deleted = 0;
        foreach (self::ENTRY_TABLES as $table) {
            $deleted += DB::table($table)->where('kiln_id', $id)->delete();
        }

        return $deleted;
    }

    /** Everything of the kiln except its profile and the owner. */
    private function factoryReset(Kiln $kiln, User $actor): int
    {
        $deleted = $this->clearEntries($kiln);
        $id = $kiln->id;

        DB::table('parties')->where('kiln_id', $id)->update(['sales_agent_id' => null]);
        DB::table('party_roles')->whereIn('party_id', DB::table('parties')->where('kiln_id', $id)->select('id'))->delete();
        foreach (self::SETUP_TABLES as $table) {
            $deleted += DB::table($table)->where('kiln_id', $id)->delete();
        }

        // Other users lose access to this kiln (their logins stay for any other kiln).
        $others = DB::table('kiln_user')->where('kiln_id', $id)->where('user_id', '!=', $actor->id)->pluck('user_id');
        DB::table('model_has_roles')->where('kiln_id', $id)->whereIn('model_id', $others)->where('model_type', User::class)->delete();
        DB::table('model_has_permissions')->where('kiln_id', $id)->whereIn('model_id', $others)->where('model_type', User::class)->delete();
        DB::table('kiln_user')->where('kiln_id', $id)->whereIn('user_id', $others)->delete();

        // A login with no kiln left can no longer be used.
        User::query()->whereIn('id', $others)->whereDoesntHave('kilns')->update(['is_active' => false]);

        return $deleted;
    }

    /** The other users of the kiln are logged out (their screens would show deleted records). */
    private function logOutOthers(Kiln $kiln, User $actor): void
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return;
        }

        $users = User::query()->whereHas('kilns', fn ($q) => $q->whereKey($kiln->id))->pluck('id');
        DB::table('sessions')->whereIn('user_id', $users)->where('user_id', '!=', $actor->id)->delete();
    }
}
