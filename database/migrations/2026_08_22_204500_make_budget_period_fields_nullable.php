<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budgets')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE budgets ALTER COLUMN period_type DROP NOT NULL');
            DB::statement('ALTER TABLE budgets ALTER COLUMN start_date DROP NOT NULL');
            DB::statement('ALTER TABLE budgets ALTER COLUMN end_date DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE budgets MODIFY period_type VARCHAR(20) NULL');
            DB::statement('ALTER TABLE budgets MODIFY start_date DATE NULL');
            DB::statement('ALTER TABLE budgets MODIFY end_date DATE NULL');
        }

        // sqlite (tests / fresh installs): create migration already defines these as nullable.
    }

    public function down(): void
    {
        if (! Schema::hasTable('budgets')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("UPDATE budgets SET period_type = 'custom' WHERE period_type IS NULL");
            DB::statement("UPDATE budgets SET start_date = CURRENT_DATE WHERE start_date IS NULL");
            DB::statement("UPDATE budgets SET end_date = CURRENT_DATE WHERE end_date IS NULL");
            DB::statement('ALTER TABLE budgets ALTER COLUMN period_type SET NOT NULL');
            DB::statement('ALTER TABLE budgets ALTER COLUMN start_date SET NOT NULL');
            DB::statement('ALTER TABLE budgets ALTER COLUMN end_date SET NOT NULL');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("UPDATE budgets SET period_type = 'custom' WHERE period_type IS NULL");
            DB::statement('UPDATE budgets SET start_date = CURRENT_DATE WHERE start_date IS NULL');
            DB::statement('UPDATE budgets SET end_date = CURRENT_DATE WHERE end_date IS NULL');
            DB::statement('ALTER TABLE budgets MODIFY period_type VARCHAR(20) NOT NULL');
            DB::statement('ALTER TABLE budgets MODIFY start_date DATE NOT NULL');
            DB::statement('ALTER TABLE budgets MODIFY end_date DATE NOT NULL');
        }
    }
};
