<?php

namespace App\Modules;

use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Throwable;

final class ModuleNodeScanner
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleAutoloader $autoloader,
    ) {}

    public function getNodeList(): array
    {
        $nodes = [];

        foreach ($this->modules->enabled() as $manifest) {
            $nodes = array_merge($nodes, $this->scanManifestControllers($manifest));
        }

        return $nodes;
    }

    /**
     * @return array<int, array{node:string,title:?string,is_auth:bool,type:int}>
     */
    private function scanManifestControllers(ModuleManifest $manifest): array
    {
        $controllersPath = $manifest->controllersPath();
        if (! is_dir($controllersPath)) {
            return [];
        }

        $this->autoloader->register($manifest);

        $nodes = [];
        foreach ($this->controllerFiles($controllersPath) as $file) {
            $basePath = rtrim($controllersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $relativeFile = str_replace('\\', '/', substr($file->getPathname(), strlen($basePath)));
            $className = $this->buildControllerClass($manifest, $relativeFile);

            if ($className === null || $this->shouldSkipController($className)) {
                continue;
            }

            $nodes = array_merge($nodes, $this->scanController($manifest, $className, $relativeFile));
        }

        return $nodes;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function controllerFiles(string $controllersPath): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($controllersPath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            yield $file;
        }
    }

    private function buildControllerClass(ModuleManifest $manifest, string $relativeFile): ?string
    {
        if (! str_ends_with($relativeFile, '.php')) {
            return null;
        }

        $relativeClass = substr($relativeFile, 0, -4);
        $segments = array_values(array_filter(explode('/', $relativeClass), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        return $manifest->namespace().'\\Controllers\\'.implode('\\', $segments);
    }

    private function shouldSkipController(string $className): bool
    {
        $controllerName = strtolower((string) preg_replace('/Controller$/', '', class_basename($className)));
        $noAuthControllers = array_map('strtolower', (array) config('admin.no_auth_controller', []));

        return in_array($controllerName, $noAuthControllers, true);
    }

    /**
     * @return array<int, array{node:string,title:?string,is_auth:bool,type:int}>
     */
    private function scanController(ModuleManifest $manifest, string $className, string $relativeFile): array
    {
        try {
            if (! class_exists($className)) {
                $controllerFile = $manifest->controllersPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
                if (is_file($controllerFile)) {
                    require_once $controllerFile;
                }
            }

            if (! class_exists($className)) {
                return [];
            }

            $reflectionClass = new ReflectionClass($className);
        } catch (Throwable) {
            return [];
        }

        $controllerNode = $this->formatControllerNode($manifest, $relativeFile);
        $ignoredMethods = $this->ignoredMethods($reflectionClass);
        $actionNodes = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->name, $ignoredMethods, true)) {
                continue;
            }

            foreach ($method->getAttributes(NodeAnnotation::class) as $attribute) {
                $annotation = $attribute->newInstance();
                $ignore = $annotation->ignore;
                if (is_string($ignore) && strtolower($ignore) === 'node') {
                    continue;
                }

                $actionNodes[] = [
                    'node' => $controllerNode.'/'.$method->name,
                    'title' => $annotation->title ?: null,
                    'is_auth' => $annotation->auth ?? false,
                    'type' => 2,
                ];
            }
        }

        if ($actionNodes === []) {
            return [];
        }

        $nodes = [];
        foreach ($reflectionClass->getAttributes(ControllerAnnotation::class) as $attribute) {
            $annotation = $attribute->newInstance();
            $nodes[] = [
                'node' => $controllerNode,
                'title' => $annotation->title ?: null,
                'is_auth' => $annotation->auth ?? false,
                'type' => 1,
            ];
        }

        return array_merge($nodes, $actionNodes);
    }

    /**
     * @return array<int, string>
     */
    private function ignoredMethods(ReflectionClass $reflectionClass): array
    {
        if (! $reflectionClass->hasProperty('ignoreNode')) {
            return [];
        }

        $property = $reflectionClass->getProperty('ignoreNode');
        $attributes = $property->getAttributes(NodeAnnotation::class);
        if ($attributes === []) {
            return [];
        }

        $ignore = $attributes[0]->newInstance()->ignore;

        return array_values(array_filter(is_array($ignore) ? $ignore : [$ignore], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    private function formatControllerNode(ModuleManifest $manifest, string $relativeFile): string
    {
        $relativeClass = substr($relativeFile, 0, -4);
        $segments = array_map(
            static function (string $segment): string {
                $segment = preg_replace('/Controller$/', '', $segment) ?? $segment;

                return lcfirst($segment);
            },
            array_values(array_filter(explode('/', $relativeClass), static fn (string $segment): bool => $segment !== ''))
        );

        return $manifest->adminPrefix().'/'.implode('/', $segments);
    }
}
