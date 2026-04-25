<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->decimal('rate', 6, 4)->nullable()->after('rate_percent');
            $table->boolean('is_default')->default(false)->after('is_exempt');
        });

        Schema::create('tax_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->date('due_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'type', 'status']);
            $table->unique(['team_id', 'type', 'period_start', 'period_end'], 'tax_period_unique_window');
        });

        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_period_id')->constrained('tax_periods')->cascadeOnDelete();
            $table->unsignedBigInteger('output_vat_cents')->default(0);
            $table->unsignedBigInteger('input_vat_cents')->default(0);
            $table->bigInteger('net_vat_cents')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->unique('tax_period_id');
            $table->index(['team_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_returns');
        Schema::dropIfExists('tax_periods');

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn(['rate', 'is_default']);
        });
    }
};
