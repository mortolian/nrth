<?php

namespace App\Modules\Wealth\Providers;

use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Modules\Wealth\Contracts\WealthAssetValueProvider;
use App\Modules\Wealth\Services\ModuleWealthAssetValueProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WealthAssetValueProvider::class, ModuleWealthAssetValueProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware([
            'web',
            'auth:sanctum',
            config('jetstream.auth_session'),
            'verified',
            EnforceSessionIdleTimeout::class,
            'team.module:wealth',
        ])->prefix('wealth')->name('wealth.')->group(__DIR__.'/../routes/web.php');
    }
}
