<?php

use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIncompleteOnboarding;
use App\Http\Middleware\SyncSpatieTeamRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->append(ForceHttps::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SyncSpatieTeamRole::class,
            RedirectIncompleteOnboarding::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 403 || ! $request->header('X-Inertia')) {
                return $response;
            }

            $message = __('You do not have permission to do that.');
            if ($e instanceof HttpExceptionInterface) {
                $exceptionMessage = trim((string) $e->getMessage());
                if (
                    $exceptionMessage !== ''
                    && ! in_array($exceptionMessage, ['Forbidden', 'This action is unauthorized.'], true)
                ) {
                    $message = $exceptionMessage;
                }
            }

            // Soft Inertia redirects from the exception path can leave the client visit
            // stuck (clicks stop until a full refresh). A location response forces a
            // clean document load while preserving the flash toast.
            $fallback = route('dashboard');
            $previous = url()->previous($fallback);
            $target = $previous === $request->fullUrl() ? $fallback : $previous;

            $request->session()->flash('error', $message);

            return Inertia::location($target);
        });
    })->create();
