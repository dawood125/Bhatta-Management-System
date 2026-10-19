<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one-time key of the form that created the party: submitting the same "Add party" form
 * twice returns the first party instead of creating a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('creation_key', 64)->nullable()->after('is_active');
            $table->unique(['kiln_id', 'creation_key']);
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['kiln_id', 'creation_key']);
            $table->dropColumn('creation_key');
        });
    }
};
