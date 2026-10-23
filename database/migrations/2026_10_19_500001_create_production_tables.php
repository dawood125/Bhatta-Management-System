<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production and brick stock (M6).
 *
 * Brick stock is never stored: it is the sum of brick_movements between the fixed stages
 * (raw -> in firing -> fired awaiting Nakaasi -> saleable by product). Quantities are BIGINT
 * thousandths of the product's unit (bricks are whole numbers). Entries are never edited or
 * deleted; a mistake is reversed with mirror movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('kind', 15)->comment('pathai | loading | firing | nakaasi | adjustment');
            $table->string('number', 30);
            $table->date('entry_date');
            $table->string('team', 100)->nullable()->comment('Pathai: who made the bricks (workers are linked in M7)');
            $table->unsignedBigInteger('made')->nullable()->comment('Pathai: bricks made, thousandths');
            $table->unsignedBigInteger('rejected')->nullable()->comment('Pathai: bricks rejected, thousandths');
            $table->unsignedBigInteger('quantity')->default(0)->comment('Bricks accepted / loaded / fired / unloaded / adjusted, thousandths');
            $table->string('notes')->nullable();
            $table->foreignId('material_usage_id')->nullable()->unique()->constrained()->restrictOnDelete()->comment('Firing: fuel used');
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->unique(['kiln_id', 'idempotency_key']);
            $table->index(['kiln_id', 'entry_date']);
            $table->index(['kiln_id', 'kind', 'entry_date']);
        });

        Schema::create('brick_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_entry_id')->constrained()->restrictOnDelete();
            $table->date('movement_date');
            $table->string('stage_from', 30)->nullable()->comment('Stage kind the bricks leave (null: they come from outside the stages)');
            $table->string('stage_to', 30)->nullable()->comment('Stage kind the bricks enter (null: they leave the stages)');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete()->comment('Grade or by-product on the saleable side; null for ungraded stages');
            $table->unsignedBigInteger('quantity')->comment('Thousandths of the unit');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('brick_movements')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'stage_to', 'product_id', 'movement_date'], 'brick_movements_in_index');
            $table->index(['kiln_id', 'stage_from', 'product_id', 'movement_date'], 'brick_movements_out_index');
            $table->index(['kiln_id', 'movement_date']);
        });

        // A movement goes somewhere, and always moves a positive quantity (MySQL 8 / MariaDB 10.2+).
        DB::statement('ALTER TABLE brick_movements ADD CONSTRAINT brick_movements_valid CHECK ('
            .'quantity > 0 AND (stage_from IS NOT NULL OR stage_to IS NOT NULL) AND (stage_from IS NULL OR stage_to IS NULL OR stage_from <> stage_to))');
    }

    public function down(): void
    {
        Schema::dropIfExists('brick_movements');
        Schema::dropIfExists('production_entries');
    }
};
