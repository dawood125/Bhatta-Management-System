<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parties (M3): customers, suppliers, drivers and sales agents. A party's balance is never
 * stored; it comes from journal lines tagged with the party on the receivable/payable accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('name_ur', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_2', 20)->nullable();
            $table->string('address')->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->bigInteger('opening_balance')->default(0)->comment('Paisa. Positive: they owe us (receivable). Negative: we owe them (payable)');
            $table->date('opening_date');
            $table->foreignId('opening_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kiln_id', 'name']);
            $table->index(['kiln_id', 'phone']);
        });

        Schema::create('party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('role', 20)->comment('customer | supplier | driver | agent');
            $table->timestamps();

            $table->unique(['party_id', 'role']);
            $table->index('role');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('ledger_account_id')->constrained()->restrictOnDelete();
            $table->index(['kiln_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['kiln_id', 'party_id']);
            $table->dropConstrainedForeignId('party_id');
        });
        Schema::dropIfExists('party_roles');
        Schema::dropIfExists('parties');
    }
};
