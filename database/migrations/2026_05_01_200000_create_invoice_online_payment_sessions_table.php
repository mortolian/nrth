<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_online_payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('provider_checkout_id')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'invoice_id', 'status']);
            $table->index(['provider', 'provider_checkout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_online_payment_sessions');
    }
};
