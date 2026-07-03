<?php

namespace App\Modules;

use Illuminate\Support\Str;

final class ModuleRouteResolver
{
    /**
     * @var array<string, bool>
     */
    private static array $registeredAutoloaders = [];

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
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
            $this->registerModuleAutoloader($manifest);
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

    private function registerModuleAutoloader(ModuleManifest $manifest): void
    {
        $namespace = trim($manifest->namespace(), '\\').'\\';
        $sourceRoot = $manifest->path().DIRECTORY_SEPARATOR.'src';
        $sourceRootReal = realpath($sourceRoot);

        if ($sourceRootReal === false) {
            return;
        }

        $key = $namespace.'|'.$sourceRootReal;
        if (isset(self::$registeredAutoloaders[$key])) {
            return;
        }

        self::$registeredAutoloaders[$key] = true;

        spl_autoload_register(static function (string $class) use ($namespace, $sourceRootReal): void {
            if (! str_starts_with($class, $namespace)) {
                return;
            }

            $relativeClass = substr($class, strlen($namespace));
            if ($relativeClass === '' || preg_match('/[^A-Za-z0-9_\\\\]/', $relativeClass) === 1) {
                return;
            }

            $file = $sourceRootReal.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass).'.php';
            $fileReal = realpath($file);

            if ($fileReal !== false && self::isPathWithin($sourceRootReal, $fileReal)) {
                require_once $fileReal;
            }
        });
    }

    private static function isPathWithin(string $root, string $path): bool
    {
        $root = str_replace('\\', '/', $root);
        $path = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Za-z]:/', $root) === 1) {
            $root = strtolower($root);
            $path = strtolower($path);
        }

        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }
}
