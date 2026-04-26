<?php

namespace App\Providers;

use App\Http\Controllers\Web\Jetstream\TeamController as AppTeamController;
use App\Http\Controllers\Web\UserProfileController;
use App\Support\EnsureTeamSpatieRoles;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Http\Controllers\Inertia\TeamController as JetstreamTeamController;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JetstreamUserProfileController::class, UserProfileController::class);
        $this->app->bind(JetstreamTeamController::class, AppTeamController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->mergeNodePathForOctaneFileWatcher();

        EnsureTeamSpatieRoles::sync();
    }

    /**
     * Octane's Node file-watcher runs under vendor/laravel/octane/bin; ensure NODE_PATH includes
     * the app node_modules so `chokidar` resolves (Sail volumes / bind mounts). Done here so we
     * do not register an extra console class that Docker's optimized autoload can omit.
     */
    private function mergeNodePathForOctaneFileWatcher(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (! in_array('octane:start', $argv, true) || ! in_array('--watch', $argv, true)) {
            return;
        }

        $nodeModules = base_path('node_modules');
        if (! is_dir($nodeModules)) {
            return;
        }

        $previous = getenv('NODE_PATH');
        $merged = $nodeModules.(($previous !== false && $previous !== '') ? PATH_SEPARATOR.$previous : '');

        putenv('NODE_PATH='.$merged);
        $_ENV['NODE_PATH'] = $merged;
        $_SERVER['NODE_PATH'] = $merged;
    }
}
