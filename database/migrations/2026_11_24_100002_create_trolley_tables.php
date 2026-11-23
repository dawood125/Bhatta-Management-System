<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kiln's own trolleys and their running costs (client, 2026-11-24). A trolley's driver earns
 * the carriage of its trips and is paid monthly, less the diesel and maintenance on these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trolleys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('driver_id')->constrained('parties')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->index(['kiln_id', 'driver_id']);
        });

        Schema::create('trolley_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->date('cost_date');
            $table->foreignId('trolley_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('parties')->restrictOnDelete();
            $table->string('kind', 15)->comment('diesel | maintenance | other');
            $table->foreignId('treasury_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Paisa');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'driver_id', 'cost_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trolley_costs');
        Schema::dropIfExists('trolleys');
    }
};
