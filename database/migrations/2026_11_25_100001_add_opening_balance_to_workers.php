<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A worker's opening balance (client, 2026-11-25): wages already due, or an advance already
 * taken, when the kiln starts using the system. Posted like a party's opening balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->bigInteger('opening_balance')->default(0)->after('notes')
                ->comment('Paisa. Positive: the kiln owes the worker (wages due). Negative: the worker owes the kiln (advance)');
            $table->date('opening_date')->nullable()->after('opening_balance');
            $table->foreignId('opening_entry_id')->nullable()->after('opening_date')->constrained('journal_entries')->restrictOnDelete();
            $table->string('creation_key', 64)->nullable()->unique()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_entry_id');
            $table->dropUnique(['creation_key']);
            $table->dropColumn(['opening_balance', 'opening_date', 'creation_key']);
        });
    }
};
