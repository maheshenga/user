<?php

use App\Http\Controllers\Api\V1\AuthController;
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

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
        Route::get('/profile', [AuthController::class, 'profile'])->middleware('api.ability:profile:read');
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
