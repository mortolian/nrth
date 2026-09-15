<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->foreignId('category_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('paid_from_banking_account_id')->constrained('banking_accounts')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('generate_on_weekday')->nullable();
            $table->unsignedTinyInteger('generate_on_day')->nullable();
            $table->boolean('generate_on_last_day')->default(false);
            $table->unsignedTinyInteger('generate_on_month')->nullable();
            $table->string('limit_type', 20)->default('none');
            $table->unsignedInteger('limit_count')->nullable();
            $table->date('limit_end_date')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->date('next_run_date');
            $table->dateTime('last_generated_at')->nullable();
            $table->tinyInteger('period_offset_months')->default(0);
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedInteger('amount_excl_vat_cents');
            $table->string('vat_rate', 20);
            $table->unsignedInteger('vat_amount_cents')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'next_run_date']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('recurring_expenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_expense_id');
        });

        Schema::dropIfExists('recurring_expenses');
    }
};
