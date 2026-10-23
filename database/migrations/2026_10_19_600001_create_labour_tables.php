<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labour and payroll (M7).
 *
 * Each worker has an account in the "Wages payable" ledger account (journal_lines.worker_id):
 * earnings credit it, advances, deductions and payments debit it. Work entries, attendance and
 * settlements keep the details; the money is always in the journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->nullable()->comment('Stable key of a default category');
            $table->string('name', 90);
            $table->string('name_ur', 90)->nullable();
            $table->string('basis', 20)->comment('per_thousand | per_day | per_month | lump_sum');
            $table->string('activity', 20)->nullable()->comment('Production work it is paid for: pathai | loading | nakaasi');
            $table->foreignId('ledger_account_id')->nullable()->unique()->constrained()->restrictOnDelete()
                ->comment('Labour expense account, created with the first earnings');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
            $table->unique(['kiln_id', 'code']);
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('name_ur', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('labour_category_id')->constrained()->restrictOnDelete();
            $table->date('joined_on')->nullable()->comment('Salary counts from this day');
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kiln_id', 'labour_category_id']);
        });

        Schema::create('labour_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('labour_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->restrictOnDelete()->comment('Null: the category rate; set: this worker\'s own rate');
            $table->date('effective_from');
            $table->unsignedBigInteger('rate')->comment('Paisa per 1,000 bricks / per day / per month');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'labour_category_id', 'worker_id', 'effective_from'], 'labour_rates_lookup_index');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->after('party_id')->constrained()->restrictOnDelete();
            $table->index(['kiln_id', 'worker_id']);
        });

        Schema::create('work_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->constrained()->restrictOnDelete();
            $table->foreignId('labour_category_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('basis', 20);
            $table->unsignedBigInteger('quantity')->nullable()->comment('Thousandths: bricks, days or paid days; null for a lump sum');
            $table->unsignedBigInteger('rate')->nullable()->comment('Paisa, as on the rate card that day');
            $table->foreignId('labour_rate_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('description')->nullable();
            $table->foreignId('production_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('salary_month', 7)->nullable()->comment('YYYY-MM for a monthly salary');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'work_date']);
            $table->index(['kiln_id', 'worker_id', 'work_date']);
            $table->index(['kiln_id', 'worker_id', 'salary_month']);
        });

        Schema::table('production_entries', function (Blueprint $table) {
            $table->foreignId('worker_id')->nullable()->after('team')->constrained()->restrictOnDelete()->comment('Who did the work; earns at the rate card');
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('status', 10)->comment('present | half | absent');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['worker_id', 'attendance_date']);
            $table->index(['kiln_id', 'attendance_date']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete()->comment('Null when nothing was paid');
            $table->string('number', 30);
            $table->date('settlement_date');
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total_paid');
            $table->string('notes')->nullable();
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->unique(['kiln_id', 'idempotency_key']);
            $table->index(['kiln_id', 'settlement_date']);
        });

        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->constrained()->restrictOnDelete();
            $table->bigInteger('brought_forward')->comment('Balance before the period (+ = owed to the worker)');
            $table->bigInteger('earned')->comment('Earnings in the period (net of reversals)');
            $table->bigInteger('advances')->comment('Advances and earlier payments in the period, net of money paid back');
            $table->bigInteger('deductions')->comment('Deductions in the period');
            $table->bigInteger('payable')->comment('Balance at the end of the period (+ = owed to the worker)');
            $table->unsignedBigInteger('paid');
            $table->bigInteger('remaining');
            $table->timestamps();

            $table->unique(['settlement_id', 'worker_id']);
            $table->index(['kiln_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('attendances');
        Schema::table('production_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('worker_id'));
        Schema::dropIfExists('work_entries');
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['kiln_id', 'worker_id']);
            $table->dropConstrainedForeignId('worker_id');
        });
        Schema::dropIfExists('labour_rates');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('labour_categories');
    }
};
