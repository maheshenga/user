<?php

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\CheckLogin;
use App\Http\Middleware\LegacyModuleClientDeprecation;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use Illuminate\Support\Facades\Route;
use Modules\QingyuIpAgent\Controllers\ApiController;
use Modules\QingyuIpAgent\Controllers\ClientController;

$legacyClientRoutes = (array) config('modules.legacy_client_routes.qingyu_ip_agent.successors', []);
foreach ($legacyClientRoutes as $action => $successor) {
    Route::match(
        ['get', 'post'],
        trim((string) config('admin.admin_alias_name', 'admin'), '/').'/qingyu_ip_agent/client/'.$action,
        [ClientController::class, $action]
    )->middleware([
        'web',
        CheckInstall::class,
        RateLimiting::class,
        CheckLogin::class,
        SystemLog::class,
        CheckAuth::class,
        LegacyModuleClientDeprecation::class.':qingyu_ip_agent,'.$successor,
    ]);
}

Route::prefix('api/v1/modules/qingyu-ip-agent')
    ->middleware(['api', CheckInstall::class])
    ->group(function (): void {
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::get('/bootstrap', [ApiController::class, 'bootstrap']);
            Route::get('/sample-audio', [ApiController::class, 'sampleAudio']);
        });

        Route::middleware([
            'auth:sanctum',
            'api.active',
            'throttle:60,1',
            'api.ability:module:qingyu_ip_agent',
            'api.module_active',
            'api.module_context',
        ])->group(function (): void {
            Route::post('/activation-codes/redeem', [ApiController::class, 'activate'])
                ->middleware('api.ability:activation:redeem');
            Route::post('/content/parse', [ApiController::class, 'parseContent'])
                ->middleware('api.ability:content:parse');
            Route::post('/content/rewrite', [ApiController::class, 'rewrite'])
                ->middleware('api.ability:content:rewrite');
        });
    });
