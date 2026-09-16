<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('receipt_not_required')->default(false)->after('expense_meta');
        });

        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->boolean('receipt_not_required')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('receipt_not_required');
        });

        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropColumn('receipt_not_required');
        });
    }
};
