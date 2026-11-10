<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_transactions', function (Blueprint $table) {
            $table->index(['team_id', 'transaction_date'], 'banking_transactions_team_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('banking_transactions', function (Blueprint $table) {
            $table->dropIndex('banking_transactions_team_date_idx');
        });
    }
};
