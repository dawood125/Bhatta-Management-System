<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner chooses at each settlement how much of a worker's advance to take back from his
 * wages: all, half or none (client, 2026-11-25). The payslip keeps what was taken back and
 * what is left. Null on settlements made before this choice existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('advance_taken_back')->nullable()->after('payable')->comment('Paisa: advance taken back from the wages this time');
            $table->unsignedBigInteger('advance_left')->nullable()->after('advance_taken_back')->comment('Paisa: advance still owed after this settlement');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->dropColumn(['advance_taken_back', 'advance_left']);
        });
    }
};
