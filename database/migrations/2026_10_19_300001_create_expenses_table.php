<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, receipts and expenses (M4).
 *
 * Payment and receipt vouchers are journal entries (type payment / receipt) and need no table of
 * their own. An expense keeps its category, sub-category and details here; the money side is its
 * journal entry: debit the category's expense account, credit the cash/bank account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('ledger_account_id')->nullable()->after('kiln_id')
                ->comment('Expense account in the chart of accounts, created with the first expense')
                ->unique()->constrained()->restrictOnDelete();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->date('expense_date');
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_sub_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Paisa; equals the journal entry total');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'expense_date']);
            $table->index(['kiln_id', 'expense_category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ledger_account_id');
        });
    }
};
