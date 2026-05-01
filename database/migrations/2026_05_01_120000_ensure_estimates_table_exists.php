<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair path for environments where `estimates` is missing (e.g. migration
     * history renamed, or migrate was never run after the quotes → estimates change).
     */
    public function up(): void
    {
        if (Schema::hasTable('estimates')) {
            return;
        }

        if (Schema::hasTable('quotes')) {
            Schema::rename('quotes', 'estimates');

            return;
        }

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('number', 32);
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('vat_amount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->json('line_items');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'number']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        // Non-destructive: do not drop `estimates` here; earlier migrations own lifecycle.
    }
};
