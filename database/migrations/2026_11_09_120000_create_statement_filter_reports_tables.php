<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_filter_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['team_id', 'name']);
        });

        Schema::create('statement_filter_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statement_filter_report_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('match');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['statement_filter_report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_filter_rules');
        Schema::dropIfExists('statement_filter_reports');
    }
};
