<?php

use App\Http\Api\ApiError;
use App\Http\Api\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Packages/Console',
        __DIR__.'/../app/Sync/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $proxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : ($proxies ? explode(',', $proxies) : null));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiError::class]);
        $exceptions->render(static fn (Throwable $e, Request $request) => $request->is('api/v2/*') ? ApiErrorResponse::of($e) : null);
    })->create();
