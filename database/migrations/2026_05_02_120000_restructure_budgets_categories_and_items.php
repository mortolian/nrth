<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('budget_lines');

        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('envelope_cents')->default(0);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_category_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedBigInteger('monthly_amount_cents')->default(0);
            $table->string('currency', 3);
            $table->unsignedBigInteger('monthly_budget_currency_cents')->default(0);
            $table->decimal('fx_budget_per_line_major', 20, 10)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['budget_category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_items');
        Schema::dropIfExists('budget_categories');

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
};
