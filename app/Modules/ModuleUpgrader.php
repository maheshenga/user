<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ModuleUpgrader
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleFileStore $files,
        private readonly ModuleZipExtractor $zips,
        private readonly ModuleVersionRecorder $versions,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleInstaller $installer,
    ) {}

    public function upgradeLocal(string $name, ?int $actorId = null): void
    {
        $module = $this->installedModule($name);
        $manifest = ModuleManifest::fromFile(rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');

        $this->upgradeInstalled($manifest, (string) $module->status, (string) $module->version, $actorId);
    }

    public function upgradeZip(string $zipPath, ?string $expectedName = null, ?int $actorId = null): void
    {
        $extracted = $this->zips->extract($zipPath);
        $manifest = ModuleManifest::fromFile($extracted.DIRECTORY_SEPARATOR.'module.json');

        try {
            if ($expectedName !== null && $manifest->name() !== $expectedName) {
                throw new InvalidArgumentException("Expected module [{$expectedName}], got [{$manifest->name()}].");
            }

            $module = $this->repository->installed($manifest->name());
            if ($module === null) {
                $target = rtrim((string) config('modules.path', base_path('modules')), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.Str::studly($manifest->name());
                $this->files->replace($target, $extracted);
                $this->installer->install($manifest->name(), $actorId);

                return;
            }

            $this->assertUpgradeable((string) $module->status, (string) $module->version, $manifest);
            $this->files->backup((string) $module->path, $manifest->name(), (string) $module->version);
            $this->files->replace((string) $module->path, $extracted);

            $fresh = ModuleManifest::fromFile(rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');
            $this->upgradeInstalled($fresh, (string) $module->status, (string) $module->version, $actorId);
        } finally {
            $this->cleanupExtracted($extracted);
        }
    }

    private function installedModule(string $name): \App\Models\SystemModule
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        return $module;
    }

    private function upgradeInstalled(ModuleManifest $manifest, string $status, string $currentVersion, ?int $actorId): void
    {
        $this->assertUpgradeable($status, $currentVersion, $manifest);

        try {
            DB::transaction(function () use ($manifest, $status, $currentVersion, $actorId): void {
                $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $manifest->name());
                $this->repository->updateFromManifest($manifest, $status);
                $this->versions->record($manifest);
                $this->migrations->runPending($manifest);
                $this->repository->log('upgrade', $manifest->name(), $status, $status, 'success', null, $actorId);
            });
        } catch (Throwable $exception) {
            $this->repository->setLastError($manifest->name(), $exception->getMessage());
            $this->repository->log('upgrade', $manifest->name(), $status, $status, 'failed', $exception->getMessage(), $actorId);

            throw $exception;
        }

        $this->clearCaches();
    }

    private function assertUpgradeable(string $status, string $currentVersion, ModuleManifest $manifest): void
    {
        if (! in_array($status, ['installed', 'enabled', 'disabled'], true)) {
            throw new InvalidArgumentException("Module [{$manifest->name()}] cannot be upgraded from status [{$status}]");
        }

        if (version_compare($manifest->version(), $currentVersion, '<=')) {
            throw new InvalidArgumentException("Module [{$manifest->name()}] version [{$manifest->version()}] must be greater than [{$currentVersion}]");
        }
    }

    private function cleanupExtracted(string $path): void
    {
        $root = is_file(dirname($path).DIRECTORY_SEPARATOR.'module.json') ? $path : dirname($path);
        $this->files->deleteDirectory($root);
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
