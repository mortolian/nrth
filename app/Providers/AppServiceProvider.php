<?php

namespace App\Providers;

use App\Actions\Jetstream\UpdateTeamMemberRole as AppUpdateTeamMemberRole;
use App\Domain\Ai\AiCatalog;
use App\Domain\Ai\AiProviderRegistry;
use App\Domain\Ai\AnthropicProvider;
use App\Domain\Ai\GeminiProvider;
use App\Domain\Ai\OpenAiCompatibleClient;
use App\Domain\Ai\OpenAiCompatibleProvider;
use App\Domain\Banking\Importers\CsvBankStatementImporter;
use App\Domain\Banking\Importers\OfxBankStatementImporter;
use App\Domain\Banking\Services\BankingStatementImporterRegistry;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceOperatorService;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Http\Controllers\Web\Jetstream\TeamController as AppTeamController;
use App\Http\Controllers\Web\Jetstream\TeamInvitationController as AppTeamInvitationController;
use App\Http\Controllers\Web\Jetstream\TeamMemberController as AppTeamMemberController;
use App\Http\Controllers\Web\UserProfileController;
use App\Models\User;
use App\Policies\TakeoutRunPolicy;
use App\Support\EnsureTeamSpatieRoles;
use App\Support\Https;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole as JetstreamUpdateTeamMemberRole;
use Laravel\Jetstream\Http\Controllers\Inertia\TeamController as JetstreamTeamController;
use Laravel\Jetstream\Http\Controllers\Inertia\TeamMemberController as JetstreamTeamMemberController;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;
use Laravel\Jetstream\Http\Controllers\TeamInvitationController as JetstreamTeamInvitationController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JetstreamUserProfileController::class, UserProfileController::class);
        $this->app->bind(JetstreamTeamController::class, AppTeamController::class);
        $this->app->bind(JetstreamTeamMemberController::class, AppTeamMemberController::class);
        $this->app->bind(JetstreamTeamInvitationController::class, AppTeamInvitationController::class);
        $this->app->bind(JetstreamUpdateTeamMemberRole::class, AppUpdateTeamMemberRole::class);

        $this->app->singleton(BankingStatementImporterRegistry::class, fn ($app): BankingStatementImporterRegistry => new BankingStatementImporterRegistry(
            $app->make(CsvBankStatementImporter::class),
            $app->make(OfxBankStatementImporter::class),
        ));

        $this->app->singleton(AiProviderRegistry::class, function ($app): AiProviderRegistry {
            $client = $app->make(OpenAiCompatibleClient::class);

            return new AiProviderRegistry([
                new OpenAiCompatibleProvider(AiCatalog::PROVIDER_OPENAI, $client),
                $app->make(AnthropicProvider::class),
                $app->make(GeminiProvider::class),
                new OpenAiCompatibleProvider(AiCatalog::PROVIDER_OPENROUTER, $client),
                new OpenAiCompatibleProvider(AiCatalog::PROVIDER_OPENAI_COMPATIBLE, $client),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (Https::shouldForce()) {
            $rootUrl = rtrim((string) config('app.url'), '/');

            // Use APP_URL (e.g. https://192.168.1.204) for redirects and generated URLs,
            // not the incoming request host:port (e.g. :8000 plain HTTP from Octane).
            if ($rootUrl !== '') {
                URL::forceRootUrl($rootUrl);
            } else {
                URL::forceScheme('https');
            }
        }

        $this->mergeNodePathForOctaneFileWatcher();

        EnsureTeamSpatieRoles::sync();

        Gate::policy(TakeoutRun::class, TakeoutRunPolicy::class);

        Gate::define('manageInstanceBackups', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            return app(InstanceOperatorService::class)->userCanManageInstance($user);
        });

        try {
            app(InstanceBackupRetentionSettings::class)->applyToConfig();
        } catch (\Throwable) {
            // DB may be unavailable during early install / migrate.
        }

        // After Jetstream registers Fortify views, enrich login for invitation joins.
        $this->app->booted(function (): void {
            Fortify::loginView(function () {
                return Inertia::render('Auth/Login', [
                    'canResetPassword' => Route::has('password.request'),
                    'status' => session('status'),
                    'invitation' => session('invitation_join'),
                ]);
            });
        });
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
