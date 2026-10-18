<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Which users may work in which kiln. Roles per kiln live in spatie's model_has_roles (kiln_id = team).
        Schema::create('kiln_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kiln_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiln_user');
        Schema::dropIfExists('kilns');
    }
};
