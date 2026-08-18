<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_transactions', function (Blueprint $table) {
            $table->string('reconciliation_status', 32)->default('unreviewed');
            $table->text('exclusion_note')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('excluded_at')->nullable();

            $table->index(['team_id', 'reconciliation_status'], 'banking_tx_team_recon_status_idx');
        });

        Schema::create('banking_transaction_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banking_transaction_id')->constrained('banking_transactions')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['banking_transaction_id', 'transaction_id'], 'banking_alloc_bank_tx_unique');
            $table->index(['team_id', 'transaction_id'], 'banking_alloc_team_tx_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_transaction_allocations');

        Schema::table('banking_transactions', function (Blueprint $table) {
            $table->dropIndex('banking_tx_team_recon_status_idx');
            $table->dropConstrainedForeignId('excluded_by');
            $table->dropColumn([
                'reconciliation_status',
                'exclusion_note',
                'excluded_at',
            ]);
        });
    }
};
