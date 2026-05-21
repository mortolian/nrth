<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_accounts')) {
            return;
        }

        Schema::rename('bank_statement_accounts', 'banking_accounts');
        Schema::rename('bank_statement_imports', 'banking_statement_imports');

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropForeign(['bank_statement_import_id']);
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->renameColumn('bank_statement_import_id', 'banking_statement_import_id');
        });

        Schema::rename('bank_transactions', 'banking_transactions');

        Schema::table('banking_transactions', function (Blueprint $table): void {
            $table->foreign('banking_statement_import_id')
                ->references('id')
                ->on('banking_statement_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('banking_accounts')) {
            return;
        }

        Schema::table('banking_transactions', function (Blueprint $table): void {
            $table->dropForeign(['banking_statement_import_id']);
        });

        Schema::rename('banking_transactions', 'bank_transactions');

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->renameColumn('banking_statement_import_id', 'bank_statement_import_id');
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreign('bank_statement_import_id')
                ->references('id')
                ->on('bank_statement_imports')
                ->nullOnDelete();
        });

        Schema::rename('banking_statement_imports', 'bank_statement_imports');
        Schema::rename('banking_accounts', 'bank_statement_accounts');
    }
};
