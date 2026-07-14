<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Middleware\CheckInstall;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware(CheckInstall::class)->group(function (): void {
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'api.active', 'throttle:60,1'])->group(function (): void {
        Route::get('/profile', [AuthController::class, 'profile'])
            ->middleware(['api.ability:profile:read', 'api.module_active']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('api.module_active');
    });
});

Route::prefix('me')->middleware(['auth:sanctum', 'api.active', 'api.module_active', 'throttle:60,1'])->group(function (): void {
    Route::get('/vip', [MeController::class, 'vip'])->middleware('api.ability:vip:read');
    Route::get('/invitations', [MeController::class, 'invitations'])->middleware('api.ability:invite:read');
    Route::get('/balance', [MeController::class, 'balance'])->middleware('api.ability:balance:read');
    Route::get('/ledger', [MeController::class, 'ledger'])->middleware('api.ability:balance:read');
});
