<?php

namespace App\Http\Middleware;

use App\Http\Controllers\admin\ErrorPageController;
use App\Http\JumpTrait;
use App\Http\Services\annotation\MiddlewareAnnotation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLogin
{
    use JumpTrait;

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response) $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminConfig = config('admin');
        $parameters = request()->route()->parameters;
        $controller = $parameters['controllerPath'] ?? $parameters['controller'] ?? 'index';

        if (! in_array($controller, $adminConfig['no_login_controller'])) {
            if (isset($parameters['secondary'], $parameters['action']) && (isset($parameters['controllerPath']) || isset($parameters['controller']))) {
                $controllerPath = (string) ($parameters['controllerPath'] ?? $parameters['controller']);
                [$className, $resolvedAction] = app(\App\Modules\ModuleRouteResolver::class)->resolve(
                    (string) $parameters['secondary'],
                    $controllerPath,
                    (string) $parameters['action'],
                );
            } else {
                $secondary = ! empty($parameters['secondary']) ? $parameters['secondary'] : '';
                $adminNamespace = config('admin.controller_namespace');
                $namespace = $adminNamespace.($secondary ? $secondary.'\\' : '');
                $className = $namespace.ucfirst($controller."Controller");
                $resolvedAction = $parameters['action'] ?? null;
            }

            try {
                $classObj = new \ReflectionClass($className);
                $properties = $classObj->getDefaultProperties();
                $ignoreLogin = $properties['ignoreLogin'] ?? false;
                if ($ignoreLogin) {
                    return $next($request);
                }

                if (! empty($resolvedAction)) {
                    $reflectionMethod = new \ReflectionMethod($className, $resolvedAction);
                    $attributes = $reflectionMethod->getAttributes(MiddlewareAnnotation::class);
                    foreach ($attributes as $attribute) {
                        $annotation = $attribute->newInstance();
                        $_ignore = (array) $annotation->ignore;
                        if (in_array('LOGIN', $_ignore, true)) {
                            return $next($request);
                        }
                    }
                }
            } catch (\ReflectionException $e) {
            }

            $adminId = session('admin.id', 0);
            $expireTime = session('admin.expire_time');
            if (empty($adminId)) {
                return $this->responseView('请先登录后台', [], __url("/login"));
            }

            if ($expireTime !== true && time() > $expireTime) {
                $request->session()->forget('admin');
                return $this->responseView('登录已过期，请重新登录', [], __url("/login"));
            }
        }

        return $next($request);
    }
}
