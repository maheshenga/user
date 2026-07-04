<?php

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\CheckLogin;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Home redirect
Route::get('/', function() {
    return redirect('/' . config('easyadmin.ADMIN'));
})->middleware([CheckInstall::class]);

// Installer
Route::controller(\App\Http\Controllers\common\InstallController::class)->group(function() {
    Route::match(['get', 'post'], '/install', 'index');
});

Route::prefix('user')->group(function (): void {
    Route::post('/register', [\App\Http\Controllers\user\AuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\user\AuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\user\AuthController::class, 'logout']);
});

Route::get('/module-assets/{module}/{path}', [\App\Http\Controllers\common\ModuleAssetController::class, 'show'])
    ->where('path', '.*')
    ->middleware([CheckInstall::class, CheckLogin::class]);

// Admin routes
$admin = config('admin.admin_alias_name');

Route::middleware([CheckInstall::class, RateLimiting::class, CheckLogin::class, SystemLog::class, CheckAuth::class])->group(function() use ($admin) {
    Route::prefix($admin)->group(function() {

        // Admin dashboard
        Route::get('/', [\App\Http\Controllers\admin\IndexController::class, 'index']);

        $adminNamespace = config('admin.controller_namespace');

        // Dynamic route: secondary/nested-controller/action
        Route::match(['get', 'post'], '/{secondary}/{controllerPath}/{action}', function($secondary, $controllerPath, $action) {
            [$className, $resolvedAction] = app(\App\Modules\ModuleRouteResolver::class)->resolve($secondary, $controllerPath, $action);
            return webRouteExtracted($className, $resolvedAction);
        })->where('controllerPath', '.+/.+');

        // Dynamic route: secondary/controller/action
        Route::match(['get', 'post'], '/{secondary}/{controller}/{action}', function($secondary, $controller, $action) {
            [$className, $resolvedAction] = app(\App\Modules\ModuleRouteResolver::class)->resolve($secondary, $controller, $action);
            return webRouteExtracted($className, $resolvedAction);
        });

        // Dynamic route: controller
        Route::match(['get', 'post'], '/{controller}/', function($controller) use ($adminNamespace) {
            $namespace = $adminNamespace;
            $className = $namespace . ucfirst($controller . "Controller");
            $action    = 'index';
            return webRouteExtracted($className, $action);
        });

        // Dynamic route: controller/action
        Route::match(['get', 'post'], '/{controller}/{action}', function($controller, $action) use ($adminNamespace) {
            $namespace = $adminNamespace;
            $className = $namespace . ucfirst($controller . "Controller");
            return webRouteExtracted($className, $action);
        });

    });
});

if (!function_exists('webRouteExtracted')) {

    function webRouteExtracted(string $className, string $action)
    {
        if (class_exists($className)) {
            $obj = new $className();
            if (method_exists($obj, $action)) {
                $reflectionClass = new ReflectionClass($className);
                $actionMethod    = $reflectionClass->getMethod($action);
                $args            = [];
                foreach ($actionMethod->getParameters() as $items) {
                    try {
                        if ($items->hasType()) {
                            $type   = $items->getType()->getName();
                            $args[] = str_contains($type, 'App\\') ? new $type() : Container::getInstance()->make($type);
                        } else {
                            $args[] = request($items->getName(), '');
                        }
                    } catch (Throwable $exception) {
                    }
                }
                return call_user_func([$obj, $action], ...$args);
            }
        }
        abort(404);
    }
}
