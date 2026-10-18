<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilns', function (Blueprint $table) {
            $table->string('address_ur')->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            $table->string('logo_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('kilns', function (Blueprint $table) {
            $table->dropColumn(['address_ur', 'email', 'logo_path']);
        });
    }
};
