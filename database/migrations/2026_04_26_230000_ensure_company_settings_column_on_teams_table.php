<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safety net: some environments skipped the original migration; PostgreSQL is unaffected by column order.
     */
    public function up(): void
    {
        if (! Schema::hasTable('teams')) {
            return;
        }

        if (Schema::hasColumn('teams', 'company_settings')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->json('company_settings')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('teams') || ! Schema::hasColumn('teams', 'company_settings')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('company_settings');
        });
    }
};
