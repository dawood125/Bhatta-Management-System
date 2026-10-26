<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed (M11): every journal line carries its entry's date. Entries never change once posted, so
 * the copy cannot go stale (ledger:verify checks it). Balances on a date and totals of a period
 * then come straight from these indexes, without reading the entries; with years of entries this
 * keeps statements, the balance sheet and the dashboard fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('journal_entry_id')->comment("Same as the entry's date");
        });

        DB::statement('UPDATE journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id SET l.entry_date = e.entry_date');

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->date('entry_date')->nullable(false)->change();

            $table->index(['kiln_id', 'ledger_account_id', 'entry_date', 'debit', 'credit'], 'journal_lines_account_date_index');
            $table->index(['kiln_id', 'party_id', 'entry_date', 'debit', 'credit'], 'journal_lines_party_date_index');
            $table->index(['kiln_id', 'worker_id', 'entry_date', 'debit', 'credit'], 'journal_lines_worker_date_index');
            $table->index(['kiln_id', 'entry_date', 'ledger_account_id', 'debit', 'credit'], 'journal_lines_date_index');

            // Covered by the new indexes above.
            $table->dropIndex(['kiln_id', 'party_id']);
            $table->dropIndex(['kiln_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index(['kiln_id', 'party_id']);
            $table->index(['kiln_id', 'worker_id']);
            $table->dropIndex('journal_lines_account_date_index');
            $table->dropIndex('journal_lines_party_date_index');
            $table->dropIndex('journal_lines_worker_date_index');
            $table->dropIndex('journal_lines_date_index');
            $table->dropColumn('entry_date');
        });
    }
};
