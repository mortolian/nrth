<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->json('address')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'name']);
        });

        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['team_id', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('number', 32);
            $table->string('reference')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('vat_amount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->text('notes')->nullable();
            $table->text('footer')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'number']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'due_date']);
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->unsignedBigInteger('unit_price_cents');
            $table->decimal('vat_rate', 5, 4)->default(0.1500);
            $table->unsignedBigInteger('vat_amount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('ZAR');
            $table->date('payment_date');
            $table->string('method', 16)->default('eft');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'payment_date']);
            $table->index(['invoice_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_number_sequences');
        Schema::dropIfExists('clients');
    }
};
