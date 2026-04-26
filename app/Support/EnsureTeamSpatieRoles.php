<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

final class EnsureTeamSpatieRoles
{
    /**
     * Idempotent: safe to call on every request (e.g. tests boot the app before migrations run).
     *
     * No-ops when the database is unreachable (e.g. composer install / package:discover without Docker).
     */
    public static function sync(): void
    {
        try {
            if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
                return;
            }

            $guard = 'web';

            if (Role::query()->where('name', 'team_owner')->where('guard_name', $guard)->exists()) {
                return;
            }

            Permission::findOrCreate('ledger.view', $guard);
            Permission::findOrCreate('ledger.export', $guard);
            Permission::findOrCreate('ledger.manage', $guard);
            Permission::findOrCreate('ledger.delete', $guard);

            $all = Permission::query()->where('guard_name', $guard)->pluck('name')->all();

            $owner = Role::findOrCreate('team_owner', $guard);
            $owner->syncPermissions($all);

            $accountant = Role::findOrCreate('team_accountant', $guard);
            $accountant->syncPermissions(['ledger.view', 'ledger.export', 'ledger.manage']);

            $viewer = Role::findOrCreate('team_viewer', $guard);
            $viewer->syncPermissions(['ledger.view']);
        } catch (Throwable) {
            // DB host down, credentials missing, or migrations not run yet.
        }
    }
}
