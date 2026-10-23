<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiln master data (M1). Every table belongs to one kiln. Rows are archived (is_active = false),
 * never deleted, so history that refers to them stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->nullable()->comment('Stable key for built-in units, e.g. "brick"');
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->string('symbol', 20)->nullable();
            $table->unsignedTinyInteger('decimals')->default(0)->comment('Decimal places allowed in quantities');
            $table->boolean('is_system')->default(false)->comment('Used by the system; cannot be archived');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'code']);
            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->string('kind', 20)->comment('fired_grade | raw_brick | by_product');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('is_saleable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'code']);
            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
            $table->index(['kiln_id', 'is_active', 'sort_order']);
        });

        Schema::create('production_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40)->comment('Fixed by the system: raw | in_firing | fired_awaiting_unloading | saleable');
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'kind']);
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kiln_id', 'name']);
            $table->unique(['kiln_id', 'name_ur']);
        });

        Schema::create('expense_sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_ur', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['expense_category_id', 'name']);
            $table->unique(['expense_category_id', 'name_ur']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_sub_categories');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('production_stages');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
    }
};
