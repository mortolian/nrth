<?php

namespace App\Modules\Wealth\Providers;

use App\Http\Middleware\EnforceSessionIdleTimeout;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
