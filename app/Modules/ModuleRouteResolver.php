<?php

namespace App\Modules;

use Illuminate\Support\Str;

final class ModuleRouteResolver
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleAutoloader $autoloader,
    ) {}

    public function resolve(string $secondary, string $controller, string $action): array
    {
        $controllerPath = trim(str_replace('\\', '/', $controller), '/');
        if ($this->reservedPrefixes->isReserved($secondary)) {
            return [$this->legacyControllerClass($secondary, $controllerPath), $action];
        }

        $manifest = $this->modules->enabledByPrefix($secondary);
        if ($manifest !== null) {
            $class = $this->moduleControllerClass($manifest, $controllerPath);
            $this->autoloader->register($manifest);
            if (! class_exists($class)) {
                $controllerFile = $this->moduleControllerFile($manifest, $controllerPath);
                if (is_file($controllerFile)) {
                    require_once $controllerFile;
                }
            }
            if (class_exists($class)) {
                return [$class, $action];
            }
        }

        return [$this->legacyControllerClass($secondary, $controllerPath), $action];
    }

    private function legacyControllerClass(string $secondary, string $controllerPath): string
    {
        $legacyController = str_contains($controllerPath, '/')
            ? Str::studly(str_replace('/', '_', $controllerPath))
            : Str::studly($controllerPath);

        return config('admin.controller_namespace').$secondary.'\\'.$legacyController.'Controller';
    }

    private function moduleControllerClass(ModuleManifest $manifest, string $controllerPath): string
    {
        $segments = array_map(
            static fn (string $segment): string => Str::studly($segment),
            array_values(array_filter(explode('/', $controllerPath)))
        );

        return $manifest->namespace().'\\Controllers\\'.implode('\\', $segments).'Controller';
    }

    private function moduleControllerFile(ModuleManifest $manifest, string $controllerPath): string
    {
        $segments = array_map(
            static fn (string $segment): string => Str::studly($segment),
            array_values(array_filter(explode('/', $controllerPath)))
        );

        return $manifest->controllersPath()
            .DIRECTORY_SEPARATOR
            .implode(DIRECTORY_SEPARATOR, $segments)
            .'Controller.php';
    }
}
