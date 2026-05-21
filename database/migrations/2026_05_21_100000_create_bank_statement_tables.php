<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_number_last4', 4)->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });

        Schema::create('banking_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('banking_accounts')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_type', 20);
            $table->string('mime_type')->nullable();
            $table->string('file_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('imported_rows')->nullable();
            $table->unsignedInteger('duplicate_rows')->nullable();
            $table->unsignedInteger('failed_rows')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'account_id']);
            $table->index(['account_id', 'file_hash']);
            $table->index('status');
        });

        Schema::create('banking_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('banking_accounts')->cascadeOnDelete();
            $table->foreignId('banking_statement_import_id')->nullable()->constrained('banking_statement_imports')->nullOnDelete();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->text('description');
            $table->string('reference')->nullable();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->string('direction', 10);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->string('source_hash', 64);
            $table->string('duplicate_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('duplicate_key');
            $table->index(['team_id', 'account_id', 'transaction_date']);
            $table->index('banking_statement_import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_transactions');
        Schema::dropIfExists('banking_statement_imports');
        Schema::dropIfExists('banking_accounts');
    }
};
