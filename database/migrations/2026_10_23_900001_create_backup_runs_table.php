<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups (M11): one row for every backup made (nightly or by the owner) and every restore drill,
 * with what came of it. A backup covers the whole database, so this table belongs to the
 * installation, not to a kiln.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->comment('scheduled | manual | drill');
            $table->string('status', 20)->comment('succeeded | failed');
            $table->string('disk', 50)->nullable();
            $table->string('path')->nullable()->comment('The backup file on the disk');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('message')->nullable()->comment('The error, if it failed');
            $table->json('details')->nullable()->comment('What a drill restored and checked');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete()->comment('Who started it, if not the schedule');
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
