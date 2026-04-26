<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('period_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('currency', 3)->default('ZAR');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['team_id', 'start_date']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('monthly_amount_cents')->default(0);
            $table->unsignedBigInteger('annual_total_cents')->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
