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

// 绯荤粺棣栭〉
Route::get('/', function() {
    return redirect('/' . config('easyadmin.ADMIN'));
})->middleware([CheckInstall::class]);

// 棣栨瀹夎绠＄悊绯荤粺
Route::controller(\App\Http\Controllers\common\InstallController::class)->group(function() {
    Route::match(['get', 'post'], '/install', 'index');
});

Route::get('/module-assets/{module}/{path}', [\App\Http\Controllers\common\ModuleAssetController::class, 'show'])
    ->where('path', '.*')
    ->middleware([CheckInstall::class, CheckLogin::class]);

// 鍚庡彴鎵€鏈夎矾鐢?
$admin = config('admin.admin_alias_name');

Route::middleware([CheckInstall::class, RateLimiting::class, CheckLogin::class, SystemLog::class, CheckAuth::class])->group(function() use ($admin) {
    Route::prefix($admin)->group(function() {

        // 鍚庡彴棣栭〉
        Route::get('/', [\App\Http\Controllers\admin\IndexController::class, 'index']);

        $adminNamespace = config('admin.controller_namespace');
        // 鍔ㄦ€佽矾鐢?(鍖归厤 secondary/controller/action)
        Route::match(['get', 'post'], '/{secondary}/{controller}/{action}', function($secondary, $controller, $action) {
            [$className, $resolvedAction] = app(\App\Modules\ModuleRouteResolver::class)->resolve($secondary, $controller, $action);
            return webRouteExtracted($className, $resolvedAction);
        });

        // 鍔ㄦ€佽矾鐢?(鍖归厤 controller)
        Route::match(['get', 'post'], '/{controller}/', function($controller) use ($adminNamespace) {
            $namespace = $adminNamespace;
            $className = $namespace . ucfirst($controller . "Controller");
            $action    = 'index';
            return webRouteExtracted($className, $action);
        });

        // 鍔ㄦ€佽矾鐢?(鍖归厤 controller/action)
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
