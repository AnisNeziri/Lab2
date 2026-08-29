<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Jobs\RefreshVesselFleetCacheJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
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

            report($exception);

            return response()->json([
                'message' => 'The request could not be completed. Please try again.',
            ], 500);
        });
    })->create();
