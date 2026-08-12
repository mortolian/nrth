<?php

use App\Support\Modules\ModuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existing businesses already used Travel / Planning / Contracting as core nav.
 * Opt them in so enabling modules by default-off does not lock current teams out.
 * New teams still get default_enabled=false (no row → disabled).
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
            ModuleCatalog::CONTRACTING,
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
                ModuleCatalog::CONTRACTING,
            ])
            ->delete();
    }
};
