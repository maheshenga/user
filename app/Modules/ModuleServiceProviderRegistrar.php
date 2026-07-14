<?php

namespace App\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ModuleServiceProviderRegistrar
{
    public function __construct(
        private readonly Application $app,
        private readonly ModuleManager $modules,
        private readonly ModuleAutoloader $autoloader,
    ) {}

    public function registerEnabled(): void
    {
        foreach ($this->modules->enabled() as $manifest) {
            $provider = $this->providerClass($manifest);
            if ($provider === null) {
                continue;
            }

            $this->autoloader->register($manifest);
            if (class_exists($provider) && is_subclass_of($provider, ServiceProvider::class)) {
                $this->app->register($provider);
            }
        }
    }

    private function providerClass(ModuleManifest $manifest): ?string
    {
        $entryPath = $manifest->entryPath();
        $sourceRoot = realpath($manifest->path().DIRECTORY_SEPARATOR.'src');
        $entryPath = $entryPath === null ? false : realpath($entryPath);
        if ($sourceRoot === false || $entryPath === false) {
            return null;
        }

        $sourceRoot = str_replace('\\', '/', $sourceRoot);
        $entryPath = str_replace('\\', '/', $entryPath);
        $relativePath = str_starts_with($entryPath, rtrim($sourceRoot, '/').'/')
            ? substr($entryPath, strlen(rtrim($sourceRoot, '/')) + 1)
            : '';
        if ($relativePath === '' || ! str_ends_with($relativePath, '.php')) {
            return null;
        }

        $relativeClass = substr($relativePath, 0, -4);

        return trim($manifest->namespace(), '\\').'\\'.str_replace('/', '\\', $relativeClass);
    }
}
