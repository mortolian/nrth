<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('journal_entry_lines');

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('status', 20)->default('draft');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('voided_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'transaction_date']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('ZAR');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('account_id');
        });

        Schema::create('tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->restrictOnDelete();
            $table->unsignedBigInteger('taxable_amount_cents');
            $table->unsignedBigInteger('tax_amount_cents');
            $table->string('type', 20);
            $table->timestamps();

            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('transactions');

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }
};
