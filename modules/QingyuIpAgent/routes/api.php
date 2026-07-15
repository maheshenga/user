<?php

use App\Http\Middleware\CheckInstall;
use Illuminate\Support\Facades\Route;
use Modules\QingyuIpAgent\Controllers\ApiController;

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
