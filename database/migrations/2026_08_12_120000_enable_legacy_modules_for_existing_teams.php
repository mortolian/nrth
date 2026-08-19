<?php

use App\Support\Modules\ModuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existing businesses already used Travel / Planning as core nav.
 * Opt them in so enabling modules by default-off does not lock current teams out.
 * New teams still get default_enabled=false (no row → disabled).
 *
 * Contracting was later removed from the product. This migration originally
 * opted teams into `contracting`; leftover rows are deleted in
 * 2026_08_19_120000_remove_retired_contracting_and_provisional_tax_rows.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_modules') || ! Schema::hasTable('teams')) {
            return;
        }

        $now = now();
        $modules = [
            ModuleCatalog::TRAVEL,
            ModuleCatalog::PLANNING,
        ];

        $teamIds = DB::table('teams')->pluck('id');

        foreach ($teamIds as $teamId) {
            foreach ($modules as $name) {
                $exists = DB::table('team_modules')
                    ->where('team_id', $teamId)
                    ->where('name', $name)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('team_modules')->insert([
                    'team_id' => $teamId,
                    'name' => $name,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('team_modules')) {
            return;
        }

        DB::table('team_modules')
            ->whereIn('name', [
                ModuleCatalog::TRAVEL,
                ModuleCatalog::PLANNING,
                'contracting',
            ])
            ->delete();
    }
};
