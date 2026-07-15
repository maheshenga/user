<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Middleware\CheckInstall;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware(CheckInstall::class)->group(function (): void {
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::prefix('modules/{module}')
            ->where(['module' => '[a-z][a-z0-9._-]{0,79}'])
            ->group(function (): void {
                Route::post('/registration-ticket', [AuthController::class, 'issueRegistrationTicket']);
                Route::post('/register', [AuthController::class, 'registerForModule']);
                Route::post('/login', [AuthController::class, 'loginForModule']);
            });
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'api.active', 'throttle:60,1'])->group(function (): void {
        Route::get('/profile', [AuthController::class, 'profile'])
            ->middleware(['api.ability:profile:read', 'api.module_active', 'api.module_context']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware(['api.module_active', 'api.module_context']);
    });
});

Route::prefix('me')->middleware(['auth:sanctum', 'api.active', 'api.module_active', 'api.module_context', 'throttle:60,1'])->group(function (): void {
    Route::get('/vip', [MeController::class, 'vip'])->middleware('api.ability:vip:read');
    Route::get('/invitations', [MeController::class, 'invitations'])->middleware('api.ability:invite:read');
    Route::get('/balance', [MeController::class, 'balance'])->middleware('api.ability:balance:read');
    Route::get('/ledger', [MeController::class, 'ledger'])->middleware('api.ability:balance:read');
});
