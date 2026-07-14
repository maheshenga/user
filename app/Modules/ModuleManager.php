<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class ModuleManager
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleArtifactHasher $hasher,
        private readonly ModuleReleaseSigner $signer,
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
    public function enabled(bool $forceIntegrityCheck = false): array
    {
        if (! Schema::hasTable('system_module')) {
            return [];
        }

        $manifests = [];
        foreach ($this->repository->enabled() as $module) {
            if ($this->reservedPrefixes->isReserved((string) $module->admin_prefix)) {
                continue;
            }

            $manifest = $this->manifestFromRow($module, $forceIntegrityCheck);
            if ($manifest !== null) {
                $manifests[$manifest->name()] = $manifest;
            }
        }

        return $manifests;
    }

    public function enabledByPrefix(string $adminPrefix): ?ModuleManifest
    {
        if (! Schema::hasTable('system_module') || $this->reservedPrefixes->isReserved($adminPrefix)) {
            return null;
        }

        $module = $this->repository->enabledByPrefix($adminPrefix);

        return $module === null ? null : $this->manifestFromRow($module);
    }

    private function manifestFromRow(SystemModule $module, bool $forceIntegrityCheck = false): ?ModuleManifest
    {
        if ($module->active_release_id !== null) {
            try {
                $this->assertActiveReleaseIntegrity($module, $forceIntegrityCheck);
            } catch (Throwable $exception) {
                $this->repository->setLastError((string) $module->name, $exception->getMessage());

                return null;
            }
        }

        $manifestPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json';

        if (! is_file($manifestPath)) {
            return null;
        }

        return $this->loadManifest($manifestPath);
    }

    private function assertActiveReleaseIntegrity(SystemModule $module, bool $force = false): void
    {
        $release = SystemModuleRelease::query()->find($module->active_release_id);
        if ($release === null || $release->status !== 'active' || $release->module !== $module->name) {
            throw new InvalidArgumentException("模块 [{$module->name}] 活动制品记录无效。");
        }

        $modulePath = $this->comparisonPath((string) $module->path);
        $releasePath = $this->comparisonPath((string) $release->artifact_path);
        if ($modulePath !== $releasePath) {
            throw new InvalidArgumentException("模块 [{$module->name}] 活动制品路径不一致。");
        }
        if (! $this->signer->verify($release)) {
            throw new InvalidArgumentException("模块 [{$module->name}] 活动制品签名校验失败。");
        }

        $cacheKey = $this->integrityCacheKey($release);
        if (! $force && $this->hasCachedIntegrity($cacheKey)) {
            return;
        }

        $actualHash = $this->hasher->hashDirectory((string) $release->artifact_path);
        if (! hash_equals((string) $release->artifact_hash, $actualHash)) {
            $this->forgetCachedIntegrity($cacheKey);
            throw new InvalidArgumentException("模块 [{$module->name}] 活动制品完整性校验失败。");
        }

        $this->cacheIntegrity($cacheKey);
    }

    private function integrityCacheKey(SystemModuleRelease $release): string
    {
        return 'module:integrity:'.hash('sha256', implode('|', [
            (string) $release->id,
            (string) $release->module,
            (string) $release->artifact_path,
            (string) $release->artifact_hash,
            (string) $release->signature_hash,
        ]));
    }

    private function hasCachedIntegrity(string $key): bool
    {
        if ($this->integrityCacheSeconds() <= 0) {
            return false;
        }

        try {
            return Cache::get($key) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIntegrity(string $key): void
    {
        $seconds = $this->integrityCacheSeconds();
        if ($seconds <= 0) {
            return;
        }

        try {
            Cache::put($key, true, $seconds);
        } catch (Throwable) {
            // Cache outages must not prevent a verified module from loading.
        }
    }

    private function forgetCachedIntegrity(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // A failed cache backend cannot weaken the fresh hash result.
        }
    }

    private function integrityCacheSeconds(): int
    {
        return max(0, (int) config('modules.integrity_cache_seconds', 60));
    }

    private function comparisonPath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));

        return preg_match('/^[A-Za-z]:/', $path) === 1 ? strtolower($path) : $path;
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
