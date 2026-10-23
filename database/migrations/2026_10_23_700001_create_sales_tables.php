<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales, deliveries and invoice emails (M8).
 *
 * A sale (invoice INV-…) is what the customer ordered: its journal entry makes the customer owe
 * the total, and its bricks are reserved until they are delivered. Each delivery (trip DS-…)
 * takes bricks out of stock through brick_movements, optionally with a driver and carriage that
 * the customer pays and the driver is owed. Money is in paisa, quantities in thousandths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->comment('Same as its journal entry, e.g. INV-2026-0001');
            $table->date('sale_date');
            $table->foreignId('customer_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total')->comment('Paisa: the lines after discounts');
            $table->string('status', 15)->comment('open | completed | cancelled');
            $table->string('notes')->nullable();
            $table->foreignId('receipt_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete()->comment('Money received with the sale');
            $table->foreignId('credit_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete()->comment('Credit note for bricks not delivered');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->unique(['kiln_id', 'idempotency_key']);
            $table->index(['kiln_id', 'sale_date']);
            $table->index(['kiln_id', 'customer_id', 'sale_date']);
            $table->index(['kiln_id', 'status']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity')->comment('Thousandths of the product unit');
            $table->unsignedBigInteger('rate')->comment('Paisa per rate_per units');
            $table->unsignedInteger('rate_per')->comment('1000 for bricks (rate per 1,000), 1 for other units');
            $table->unsignedBigInteger('discount')->default(0)->comment('Paisa');
            $table->unsignedBigInteger('amount')->comment('Paisa: rate × quantity ÷ rate_per − discount');
            $table->timestamps();

            $table->unique(['sale_id', 'line_no']);
            $table->index(['kiln_id', 'product_id']);
        });

        Schema::create('sale_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->comment('DS-2026-0001; the carriage entry has the same number');
            $table->date('dispatch_date');
            $table->foreignId('driver_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('vehicle', 30)->nullable();
            $table->unsignedBigInteger('carriage')->default(0)->comment('Paisa charged to the customer and owed to the driver');
            $table->foreignId('carriage_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('notes')->nullable();
            $table->string('idempotency_key', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['kiln_id', 'number']);
            $table->unique(['kiln_id', 'idempotency_key']);
            $table->index(['kiln_id', 'dispatch_date']);
            $table->index(['kiln_id', 'driver_id', 'dispatch_date']);
        });

        Schema::create('sale_dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_dispatch_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity')->comment('Thousandths of the product unit');
            $table->timestamps();

            $table->unique(['sale_dispatch_id', 'sale_line_id']);
            $table->index('sale_line_id');
        });

        // Receipts taken for one sale (from its page): they pay that sale first. Money received
        // with the sale itself is sales.receipt_entry_id; other receipts pay the oldest sales first.
        Schema::create('sale_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('sale_id');
        });

        // Bricks leave stock through deliveries as well as production entries: every movement
        // has exactly one of the two as its source.
        Schema::table('brick_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('production_entry_id')->nullable()->change();
            $table->foreignId('sale_dispatch_id')->nullable()->after('production_entry_id')->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE brick_movements ADD CONSTRAINT brick_movements_one_source CHECK ((production_entry_id IS NULL) <> (sale_dispatch_id IS NULL))');

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiln_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 30)->comment('sale_invoice');
            $table->string('recipients', 500);
            $table->string('subject');
            $table->string('locale', 5);
            $table->string('status', 15)->comment('queued | sent | failed');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kiln_id', 'created_at']);
        });

        Schema::table('kilns', function (Blueprint $table) {
            $table->boolean('sales_email_enabled')->default(false)->after('logo_path');
            $table->string('sales_email_to', 500)->nullable()->after('sales_email_enabled')->comment('Comma-separated addresses');
            $table->string('invoice_note', 500)->nullable()->after('sales_email_to')->comment('Printed at the bottom of invoices');
        });
    }

    public function down(): void
    {
        Schema::table('kilns', function (Blueprint $table) {
            $table->dropColumn(['sales_email_enabled', 'sales_email_to', 'invoice_note']);
        });

        Schema::dropIfExists('email_logs');

        // Movements of deliveries go with the sales tables (production entries keep theirs).
        DB::table('brick_movements')->whereNotNull('sale_dispatch_id')->delete();
        DB::statement('ALTER TABLE brick_movements DROP CONSTRAINT brick_movements_one_source');
        Schema::table('brick_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_dispatch_id');
        });
        Schema::table('brick_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('production_entry_id')->nullable(false)->change();
        });

        Schema::dropIfExists('sale_receipts');
        Schema::dropIfExists('sale_dispatch_lines');
        Schema::dropIfExists('sale_dispatches');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
