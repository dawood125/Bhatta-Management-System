<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materials and procurement (M5).
 *
 * Quantities are BIGINT thousandths of the material's unit; money is BIGINT paisa.
 * Stock is never stored: it is the sum of material_movements (quantity and value), and the
 * value side always equals the "Material stock" ledger account (ledger:verify checks it).
 * Documents are never edited or deleted; corrections are reversals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->nullable()->comment('Stable key of a default material');
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->comment('raw_material | fuel | other');
            $table->foreignId('usage_account_id')->nullable()->unique()
                ->comment('Expense account for this material when used, created with its first use')
                ->constrained('ledger_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
            $table->unique(['kiln_id', 'code']);
        });

        Schema::create('material_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->foreignId('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('payment', 10)->comment('credit: added to the supplier account | paid: paid now from an account');
            $table->foreignId('treasury_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable()->comment('Bill or slip number, vehicle number');
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('goods_total');
            $table->unsignedBigInteger('charges_total');
            $table->unsignedBigInteger('total');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'purchase_date']);
            $table->index(['kiln_id', 'supplier_id']);
        });

        Schema::create('material_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_purchase_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity')->comment('Thousandths of the unit');
            $table->unsignedBigInteger('rate')->comment('Paisa per unit');
            $table->unsignedBigInteger('amount')->comment('Quantity x rate');
            $table->unsignedBigInteger('charges')->comment('Share of the extra charges');
            $table->unsignedBigInteger('cost')->comment('Amount + charges: what goes into stock');
            $table->timestamps();

            $table->unique(['material_purchase_id', 'line_no']);
        });

        Schema::create('material_purchase_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_purchase_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('kind', 20)->comment('transport | excavator | loading | other');
            $table->string('description', 150)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('payee', 12)->comment('with_goods | party | account');
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['material_purchase_id', 'line_no']);
        });

        Schema::create('material_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('total_cost');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'usage_date']);
        });

        Schema::create('material_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('kind', 12)->comment('opening | shortage');
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('value');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'adjustment_date']);
        });

        Schema::create('material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->date('movement_date');
            $table->string('type', 20)->comment('opening | purchase | usage | shortage | reversal');
            $table->unsignedBigInteger('quantity_in')->default(0);
            $table->unsignedBigInteger('quantity_out')->default(0);
            $table->unsignedBigInteger('value_in')->default(0);
            $table->unsignedBigInteger('value_out')->default(0);
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_purchase_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('material_usage_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('material_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('material_movements')->restrictOnDelete();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'material_id', 'movement_date', 'id']);
            $table->index(['kiln_id', 'movement_date']);
        });

        // A movement either brings stock in or takes it out, never both (MySQL 8 / MariaDB 10.2+).
        DB::statement('ALTER TABLE material_movements ADD CONSTRAINT material_movements_one_side CHECK ('
            .'(quantity_in > 0 AND quantity_out = 0 AND value_out = 0) OR (quantity_out > 0 AND quantity_in = 0 AND value_in = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('material_movements');
        Schema::dropIfExists('material_adjustments');
        Schema::dropIfExists('material_usages');
        Schema::dropIfExists('material_purchase_charges');
        Schema::dropIfExists('material_purchase_lines');
        Schema::dropIfExists('material_purchases');
        Schema::dropIfExists('materials');
    }
};
