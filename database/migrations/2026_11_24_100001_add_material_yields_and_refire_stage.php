<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client requirements of 2026-11-24: raw materials can state how many bricks one unit makes
 * (so Pathai takes them out of stock), soil can be bought by the big trolley, and bricks can
 * wait to be fired again. The new stage itself is added to each kiln by kiln:defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedInteger('bricks_per_unit')->nullable()->after('kind')
                ->comment('Bricks one unit makes; set = Pathai takes it out of stock');
            $table->unsignedInteger('bricks_per_big_unit')->nullable()->after('bricks_per_unit')
                ->comment('Bricks one big trolley makes; set = it can be bought by the big trolley');
        });

        Schema::table('material_purchase_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('big_quantity')->nullable()->after('quantity')
                ->comment('Thousandths of a big trolley, when bought that way; rate is then per big trolley');
        });

        // "Waiting to re-fire" takes the place before "Ready for sale".
        DB::table('production_stages')->where('kind', 'saleable')->update(['sort_order' => 5]);
    }

    public function down(): void
    {
        DB::table('production_stages')->where('kind', 'saleable')->update(['sort_order' => 4]);
        DB::table('production_stages')->where('kind', 'awaiting_refire')->delete();

        Schema::table('material_purchase_lines', fn (Blueprint $table) => $table->dropColumn('big_quantity'));
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn(['bricks_per_unit', 'bricks_per_big_unit']));
    }
};
