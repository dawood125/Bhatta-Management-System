<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings of the whole installation (M11), set by the owner in the app instead of in .env, so a
 * new kiln needs no server work for them: the email server that sends invoices and backup
 * alerts, who gets the backup alerts, and the Google Drive account that keeps a copy of every
 * backup. One row. Passwords and tokens are encrypted with the application key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            $table->string('mail_host')->nullable();
            $table->unsignedSmallInteger('mail_port')->nullable();
            $table->string('mail_encryption', 10)->nullable()->comment('tls | ssl | none');
            $table->string('mail_username')->nullable();
            $table->text('mail_password')->nullable()->comment('Encrypted');
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();
            $table->string('backup_email_to', 500)->nullable()->comment('Comma-separated');

            $table->text('google_drive_token')->nullable()->comment('Encrypted refresh token');
            $table->string('google_drive_email')->nullable();
            $table->string('google_drive_folder_id')->nullable();
            $table->timestamp('google_drive_connected_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
