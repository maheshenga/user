<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
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
    ) {}

    public function upgradeLocal(string $name, ?int $actorId = null): void
    {
        $this->withModuleLock($name, function () use ($name, $actorId): void {
            $module = $this->installedModule($name);
            $manifest = ModuleManifest::fromFile(rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($manifest, $name);
            $this->assertUpgradeable((string) $module->status, (string) $module->version, $manifest);
            $this->files->backup((string) $module->path, $manifest->name(), (string) $module->version);

            $this->upgradeInstalled($manifest, (string) $module->status, (string) $module->version, $actorId);
        });
    }

    public function upgradeZip(string $zipPath, ?string $expectedName = null, ?int $actorId = null): void
    {
        $extracted = $this->zips->extract($zipPath);

        try {
            $manifest = ModuleManifest::fromFile($extracted.DIRECTORY_SEPARATOR.'module.json');

            $this->withModuleLock($manifest->name(), function () use ($manifest, $extracted, $expectedName, $actorId): void {
                if ($expectedName !== null && $manifest->name() !== $expectedName) {
                    $this->assertManifestName($manifest, $expectedName);
                }

                $module = $this->repository->installed($manifest->name());
                if ($module === null) {
                    $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $manifest->name());

                    $target = rtrim((string) config('modules.path', base_path('modules')), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.Str::studly($manifest->name());
                    if (file_exists($target)) {
                        throw new RuntimeException("模块目标目录已存在：{$target}");
                    }

                    $this->files->replace($target, $extracted);
                    try {
                        $this->repository->upsertDiscovered(ModuleManifest::fromFile($target.DIRECTORY_SEPARATOR.'module.json'));
                    } catch (Throwable $exception) {
                        $this->files->deleteDirectory($target);

                        throw $exception;
                    }

                    $this->clearCaches();

                    return;
                }

                $this->assertUpgradeable((string) $module->status, (string) $module->version, $manifest);
                $backup = $this->files->backup((string) $module->path, $manifest->name(), (string) $module->version);
                $this->files->replace((string) $module->path, $extracted);

                $fresh = ModuleManifest::fromFile(rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');
                try {
                    $this->upgradeInstalled($fresh, (string) $module->status, (string) $module->version, $actorId);
                } catch (Throwable $exception) {
                    $this->files->replace((string) $module->path, $backup);

                    throw $exception;
                }
            });
        } finally {
            $this->cleanupExtracted($extracted);
        }
    }

    private function installedModule(string $name): \App\Models\SystemModule
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("模块未安装：{$name}");
        }

        return $module;
    }

    private function assertManifestName(ModuleManifest $manifest, string $expectedName): void
    {
        if ($manifest->name() !== $expectedName) {
            throw new InvalidArgumentException("期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。");
        }
    }

    private function upgradeInstalled(ModuleManifest $manifest, string $status, string $currentVersion, ?int $actorId): void
    {
        $this->assertUpgradeable($status, $currentVersion, $manifest);

        try {
            DB::transaction(function () use ($manifest, $status, $currentVersion, $actorId): void {
                $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $manifest->name());
                $this->migrations->runPending($manifest);
                $this->repository->updateFromManifest($manifest, $status);
                $this->versions->record($manifest);
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
            throw new InvalidArgumentException("模块 [{$manifest->name()}] 当前状态 [{$status}] 不允许升级。");
        }

        if (version_compare($manifest->version(), $currentVersion, '<=')) {
            throw new InvalidArgumentException("模块 [{$manifest->name()}] 新版本 [{$manifest->version()}] 必须大于当前版本 [{$currentVersion}]。");
        }
    }

    private function cleanupExtracted(string $path): void
    {
        $tmp = $this->normalizePath(storage_path('modules/tmp'));
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $normalizedPath = $this->normalizePath($path);
        $parent = $this->normalizePath(dirname($path));

        if (is_file($path.DIRECTORY_SEPARATOR.'module.json') && dirname($normalizedPath) === $tmp) {
            $this->files->deleteDirectory($path);

            return;
        }

        if (is_file($path.DIRECTORY_SEPARATOR.'module.json') && dirname($parent) === $tmp) {
            $this->files->deleteDirectory(dirname($path));
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));

        return preg_match('/^[A-Za-z]:/', $path) === 1 ? strtolower($path) : $path;
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }

    /**
     * @param  callable(): void  $operation
     */
    private function withModuleLock(string $module, callable $operation): void
    {
        $dir = storage_path('modules/locks');
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("无法创建模块锁目录：{$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.$this->safeLockSegment($module).'.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("无法打开模块锁：{$path}");
        }

        try {
            $deadline = microtime(true) + 2.0;
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        $operation();
                    } finally {
                        flock($handle, LOCK_UN);
                    }

                    return;
                }

                usleep(50_000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException("模块 [{$module}] 正在升级中，请稍后再试。");
        } finally {
            fclose($handle);
        }
    }

    private function safeLockSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';

        return in_array($safe, ['', '.', '..'], true) ? '_' : $safe;
    }
}
