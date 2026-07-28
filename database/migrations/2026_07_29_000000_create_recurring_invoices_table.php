<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
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
            $table->boolean('auto_send')->default(false);
            $table->tinyInteger('period_offset_months')->default(0);
            $table->string('due_date_rule', 32)->default('client_terms');
            $table->unsignedInteger('due_days')->nullable();
            $table->unsignedTinyInteger('due_on_day')->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('footer')->nullable();
            $table->json('line_items');
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'next_run_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('recurring_invoice_id')
                ->nullable()
                ->after('client_id')
                ->constrained('recurring_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
        });

        Schema::dropIfExists('recurring_invoices');
    }
};
