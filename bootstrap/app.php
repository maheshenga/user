<?php

use App\Http\Middleware\ForceApiJsonResponse;
use App\Http\Middleware\RequireActiveApiModule;
use App\Http\Middleware\RequireActiveApiUser;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [ForceApiJsonResponse::class]);
        $middleware->alias([
            'api.ability' => RequireApiAbility::class,
            'api.active' => RequireActiveApiUser::class,
            'api.module_active' => RequireActiveApiModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
