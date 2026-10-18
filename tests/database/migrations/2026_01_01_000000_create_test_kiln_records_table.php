<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table (loaded only when running tests) used to prove the kiln-scoping and
 * Money cast behaviour that every future business table will rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_kiln_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->bigInteger('amount')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_kiln_records');
    }
};
