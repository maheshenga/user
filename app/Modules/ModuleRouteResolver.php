<?php

namespace App\Modules;

use Illuminate\Support\Str;

final class ModuleRouteResolver
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function resolve(string $secondary, string $controller, string $action): array
    {
        $manifest = $this->modules->enabledByPrefix($secondary);
        if ($manifest !== null) {
            $class = $manifest->namespace().'\\Controllers\\'.Str::studly($controller).'Controller';
            if (class_exists($class)) {
                return [$class, $action];
            }
        }

        $legacy = config('admin.controller_namespace').$secondary.'\\'.Str::studly($controller).'Controller';

        return [$legacy, $action];
    }
}
