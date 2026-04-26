<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->string('billing_type', 30);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('contract_value_cents')->default(0);
            $table->unsignedBigInteger('hourly_rate_cents')->default(0);
            $table->unsignedBigInteger('monthly_amount_cents')->default(0);
            $table->string('payment_terms')->nullable();
            $table->longText('scope_of_work')->nullable();
            $table->date('next_invoice_due_date')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'billing_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
