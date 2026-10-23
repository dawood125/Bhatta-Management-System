<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day close (M9): at the end of a day the cash is counted against the books and the day is
 * closed. Nothing new can then be dated on or before it, except by the owner, until the owner
 * reopens it. Closes are never deleted; a reopened close keeps who reopened it and why.
 * Money is in paisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->date('close_date')->comment('This day and every day before it are closed');
            $table->string('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reopen_reason')->nullable();
            $table->timestamps();

            $table->index(['kiln_id', 'reopened_at', 'close_date']);
        });

        Schema::create('day_close_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('day_close_id')->constrained()->restrictOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('opening')->comment('Paisa: balance at the end of the day before');
            $table->bigInteger('money_in')->comment('Paisa');
            $table->bigInteger('money_out')->comment('Paisa');
            $table->bigInteger('expected')->comment('Paisa: balance in the books at the end of the day');
            $table->bigInteger('counted')->nullable()->comment('Paisa: what was counted, if it was');
            $table->timestamps();

            $table->unique(['day_close_id', 'treasury_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_close_counts');
        Schema::dropIfExists('day_closes');
    }
};
