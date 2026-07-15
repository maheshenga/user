<?php

use App\Http\Middleware\ForceApiJsonResponse;
use App\Http\Middleware\EstablishModuleExecutionContext;
use App\Http\Middleware\RequireActiveApiModule;
use App\Http\Middleware\RequireActiveApiUser;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Modules\ModuleApiException;
use Illuminate\Http\Request;

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
        $middleware->web(append: [EstablishModuleExecutionContext::class]);
        $middleware->alias([
            'api.ability' => RequireApiAbility::class,
            'api.active' => RequireActiveApiUser::class,
            'api.module_active' => RequireActiveApiModule::class,
            'api.module_context' => EstablishModuleExecutionContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ModuleApiException $exception, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'data' => [],
            ], $exception->httpStatus());
        });
    })->create();
