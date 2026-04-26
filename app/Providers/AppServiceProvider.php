<?php

namespace App\Providers;

use App\Http\Controllers\Web\UserProfileController;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JetstreamUserProfileController::class, UserProfileController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureTeamSpatieRoles();
    }

    private function ensureTeamSpatieRoles(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $guard = 'web';

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
    }
}
