<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client, 2026-11-25:
 * - Bricks already at the kiln when it starts using the system: a stock adjustment marked as
 *   opening stock, so it is not mistaken for bricks found when counting.
 * - Pathai made by the machine: its operator is paid by the day, not per 1,000 bricks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_entries', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('quantity')->comment('Adjustment: opening stock');
            $table->boolean('by_machine')->default(false)->after('worker_id')->comment('Pathai: made by the machine; worker_id is its operator, paid by the day');
        });
    }

    public function down(): void
    {
        Schema::table('production_entries', function (Blueprint $table) {
            $table->dropColumn(['is_opening', 'by_machine']);
        });
    }
};
