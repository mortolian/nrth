<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_accounts', function (Blueprint $table): void {
            $table->foreignId('gl_account_id')
                ->nullable()
                ->after('is_active')
                ->constrained('accounts')
                ->nullOnDelete();

            $table->unique(['team_id', 'gl_account_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('banking_account_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('banking_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('banking_account_id');
        });

        Schema::table('banking_accounts', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'gl_account_id']);
            $table->dropConstrainedForeignId('gl_account_id');
        });
    }
};
