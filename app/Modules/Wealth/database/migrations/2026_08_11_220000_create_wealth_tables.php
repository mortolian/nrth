<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wealth_portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('base_currency', 3)->default('ZAR');
            $table->unsignedTinyInteger('financial_year_start_month')->default(3);
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_default']);
        });

        Schema::create('wealth_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('wealth_portfolios')->cascadeOnDelete();
            $table->string('name');
            $table->string('owner_name');
            $table->string('asset_type');
            $table->string('institution')->nullable();
            $table->string('currency', 3);
            $table->string('liquidity');
            $table->unsignedInteger('interest_rate_bps')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'portfolio_id', 'is_active']);
            $table->index(['portfolio_id', 'asset_type']);
            $table->index(['portfolio_id', 'owner_name']);
        });

        Schema::create('wealth_asset_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('wealth_assets')->cascadeOnDelete();
            $table->date('valued_on');
            $table->unsignedBigInteger('value_cents');
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['asset_id', 'valued_on']);
            $table->index(['team_id', 'valued_on']);
            $table->index(['asset_id', 'valued_on']);
        });

        Schema::create('wealth_asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('wealth_assets')->cascadeOnDelete();
            $table->string('type');
            $table->date('occurred_on');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->index(['asset_id', 'occurred_on']);
            $table->index(['team_id', 'occurred_on', 'type']);
        });

        Schema::create('wealth_contribution_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('wealth_portfolios')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('wealth_assets')->nullOnDelete();
            $table->string('owner_name');
            $table->string('label');
            $table->string('scheme_key')->nullable();
            $table->string('financial_year_label');
            $table->date('year_starts_on');
            $table->date('year_ends_on');
            $table->unsignedBigInteger('limit_cents');
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'portfolio_id']);
            $table->index(['asset_id', 'year_starts_on', 'year_ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wealth_contribution_allowances');
        Schema::dropIfExists('wealth_asset_transactions');
        Schema::dropIfExists('wealth_asset_valuations');
        Schema::dropIfExists('wealth_assets');
        Schema::dropIfExists('wealth_portfolios');
    }
};
