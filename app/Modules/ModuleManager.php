<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;
use Illuminate\Support\Facades\Schema;

final class ModuleManager
{
    public function __construct(
        private readonly ModuleRepository $repository,
    ) {}

    /**
     * @return array<string, ModuleManifest>
     */
    public function discover(): array
    {
        $root = config('modules.path', base_path('modules'));
        if (! is_dir($root)) {
            return [];
        }

        $modules = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $root.DIRECTORY_SEPARATOR.$entry.DIRECTORY_SEPARATOR.'module.json';
            if (is_file($manifestPath)) {
                $manifest = $this->loadManifest($manifestPath);
                if ($manifest === null) {
                    continue;
                }
                $modules[$manifest->name()] = $manifest;
            }
        }

        return $modules;
    }

    public function manifest(string $name): ?ModuleManifest
    {
        $modules = $this->discover();

        return $modules[$name] ?? null;
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function enabled(): array
    {
        if (! Schema::hasTable('system_module')) {
            return [];
        }

        $manifests = [];
        foreach ($this->repository->enabled() as $module) {
            $manifest = $this->manifestFromRow($module);
            if ($manifest !== null) {
                $manifests[$manifest->name()] = $manifest;
            }
        }

        return $manifests;
    }

    public function enabledByPrefix(string $adminPrefix): ?ModuleManifest
    {
        if (! Schema::hasTable('system_module')) {
            return null;
        }

        $module = $this->repository->enabledByPrefix($adminPrefix);

        return $module === null ? null : $this->manifestFromRow($module);
    }

    private function manifestFromRow(SystemModule $module): ?ModuleManifest
    {
        $manifestPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json';

        if (! is_file($manifestPath)) {
            return null;
        }

        return $this->loadManifest($manifestPath);
    }

    private function loadManifest(string $manifestPath): ?ModuleManifest
    {
        try {
            return ModuleManifest::fromFile($manifestPath);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
