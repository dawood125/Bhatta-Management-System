<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting core (M2): chart of accounts, double-entry journal, cash/bank accounts and
 * document numbering.
 *
 * Money is BIGINT paisa. Journal entries and lines are never updated or deleted (corrections
 * are reversal entries); the application enforces this and the tests prove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->string('type', 20)->comment('asset | liability | equity | income | expense');
            $table->string('system_key', 50)->nullable()->comment('Stable key for accounts the system posts to, e.g. owner_capital');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kiln_id', 'code']);
            $table->unique(['kiln_id', 'system_key']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'prefix', 'year']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->date('entry_date');
            $table->string('type', 30)->comment('opening_balance | transfer | capital_in | capital_out | reversal | ...');
            $table->string('status', 20)->default('posted')->comment('posted | reversed');
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('total')->comment('Sum of debits (= sum of credits), in paisa');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->unique(['kiln_id', 'idempotency_key']);
            $table->unique('reversal_of_id'); // an entry can be reversed only once
            $table->index(['kiln_id', 'entry_date']);
            $table->index(['kiln_id', 'type']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['kiln_id', 'ledger_account_id', 'journal_entry_id']);
        });

        // Exactly one side of a line carries an amount (MySQL 8 and MariaDB 10.2+ enforce CHECK).
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 20)->comment('cash | bank | wallet | person | credit_card');
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->bigInteger('opening_balance')->default(0)->comment('Paisa; posted as an opening_balance journal entry');
            $table->date('opening_date');
            $table->foreignId('opening_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->boolean('allow_negative')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_accounts');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('ledger_accounts');
    }
};
