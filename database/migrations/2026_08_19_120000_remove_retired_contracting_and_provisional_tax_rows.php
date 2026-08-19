<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contracting and provisional tax are no longer product surfaces.
 * Deletes leftover module/period/permission rows. The `contracts` table is
 * dropped in 2026_08_19_121000_drop_contracts_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_periods')) {
            DB::table('tax_periods')->where('type', 'provisional')->delete();
        }

        if (Schema::hasTable('team_modules')) {
            DB::table('team_modules')->where('name', 'contracting')->delete();
        }

        if (Schema::hasTable('team_roles')) {
            foreach (DB::table('team_roles')->where('is_system', false)->cursor() as $role) {
                $permissions = $this->jsonArray($role->permissions);
                if ($permissions === null) {
                    continue;
                }

                $filtered = array_values(array_filter(
                    $permissions,
                    fn (mixed $key): bool => $key !== 'contracts.view' && $key !== 'contracts.manage'
                ));

                if ($filtered === $permissions) {
                    continue;
                }

                DB::table('team_roles')->where('id', $role->id)->update([
                    'permissions' => json_encode($filtered),
                ]);
            }
        }

        if (Schema::hasTable('users')) {
            foreach (DB::table('users')->whereNotNull('preferences')->cursor() as $user) {
                $preferences = $this->jsonArray($user->preferences);
                if ($preferences === null || ! array_key_exists('notify_provisional_tax', $preferences)) {
                    continue;
                }

                unset($preferences['notify_provisional_tax']);

                DB::table('users')->where('id', $user->id)->update([
                    'preferences' => json_encode($preferences),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data-only cleanup. Rows are not restored.
    }

    /**
     * @return array<mixed>|null
     */
    private function jsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
