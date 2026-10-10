<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\RequestCorrelationId;
use App\Jobs\RefreshVesselFleetCacheJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestCorrelationId::class);
        $middleware->append(\App\Http\Middleware\ProductionConfiguration::class);
        // Resolve the authenticated tenant before implicit route-model binding.
        // Otherwise the company global scope has no user on a fresh request.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, AuthenticateApiToken::class);
        $middleware->alias([
            'order.channel' => \App\Http\Middleware\AuthenticateOrderChannel::class,
            'auth.token' => AuthenticateApiToken::class,
            'role' => CheckRole::class,
            'permission' => CheckPermission::class,
            'password.changed' => EnsurePasswordChanged::class,
            'company.context' => EnsureCompanyContext::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new RefreshVesselFleetCacheJob)->everyFiveMinutes();
        $schedule->command('shipments:refresh-tracking')->everyTenMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (QueryException $exception, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // Connection errors are availability failures, never empty business data.
            $unavailable = str_starts_with((string) $exception->getCode(), '08') || in_array((int) ($exception->errorInfo[1] ?? 0), [2002, 2006, 2013], true);

            return response()->json([
                'message' => $unavailable ? 'Database connection unavailable. Contact your administrator; no empty data is being substituted.' : 'The request could not be completed. Please try again.',
                'request_id' => $request->attributes->get('request_id'),
            ], $unavailable ? 503 : 500);
        });
    })->create();

// PHP's built-in HTTP SAPI may omit custom environment variables from
// $_SERVER/$_ENV. Resolve the shared storage root before loading configuration
// so HTTP, workers and CLI all use the same heartbeat and maintenance files.
$storageRoot = $_ENV['LARAVEL_STORAGE_PATH'] ?? $_SERVER['LARAVEL_STORAGE_PATH'] ?? getenv('LARAVEL_STORAGE_PATH');
if (is_string($storageRoot) && $storageRoot !== '') $application->useStoragePath($storageRoot);
return $application;
