<?php

namespace App\Providers;

use App\Http\Controllers\Web\UserProfileController;
use App\Support\EnsureTeamSpatieRoles;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;

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
        EnsureTeamSpatieRoles::sync();
    }
}
