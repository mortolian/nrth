<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('income_account_id')
                ->nullable()
                ->after('recurring_invoice_id')
                ->constrained('accounts')
                ->nullOnDelete();
            $table->foreignId('accrual_transaction_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        Schema::table('invoice_line_items', function (Blueprint $table): void {
            $table->foreignId('income_account_id')
                ->nullable()
                ->after('item_id')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('income_account_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accrual_transaction_id');
            $table->dropConstrainedForeignId('income_account_id');
        });
    }
};
