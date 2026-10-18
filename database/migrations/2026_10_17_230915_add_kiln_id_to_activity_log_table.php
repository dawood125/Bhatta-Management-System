<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Null only for events outside any kiln (e.g. a failed console command).
            $table->foreignId('kiln_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['kiln_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['kiln_id', 'created_at']);
            $table->dropConstrainedForeignId('kiln_id');
        });
    }
};
