<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budget_categories')) {
            return;
        }

        if (Schema::hasColumn('budget_categories', 'envelope_cents')) {
            Schema::table('budget_categories', function (Blueprint $table): void {
                $table->dropColumn('envelope_cents');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('budget_categories')) {
            return;
        }

        if (! Schema::hasColumn('budget_categories', 'envelope_cents')) {
            Schema::table('budget_categories', function (Blueprint $table): void {
                $table->unsignedBigInteger('envelope_cents')->default(0)->after('name');
            });
        }
    }
};
