<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class ModuleRuntimeEligibility
{
    public function __construct(
        private readonly ModuleExecutionPolicy $executionPolicy,
        private readonly ModuleArtifactHasher $hasher,
        private readonly ModuleReleaseSigner $signer,
    ) {}

    public function assertEligible(string|SystemModule $module, bool $forceIntegrityCheck = false): SystemModule
    {
        $record = $this->enabledRecord($module);
        $this->executionPolicy->assertInProcessAllowed($record);

        return $this->assertReleaseAndManifest($record, $forceIntegrityCheck);
    }

    public function assertExecutable(string|SystemModule $module, bool $forceIntegrityCheck = false): SystemModule
    {
        $record = $this->enabledRecord($module);
        $this->executionPolicy->assertExecutionAllowed($record);

        return $this->assertReleaseAndManifest($record, $forceIntegrityCheck);
    }

    private function enabledRecord(string|SystemModule $module): SystemModule
    {
        $record = is_string($module)
            ? SystemModule::query()->where('name', $module)->first()
            : $module;

        if ($record === null || $record->status !== 'enabled') {
            $name = is_string($module) ? $module : (string) $module->name;
            throw new InvalidArgumentException("模块 [{$name}] 当前未启用。");
        }

        return $record;
    }

    private function assertReleaseAndManifest(SystemModule $record, bool $forceIntegrityCheck): SystemModule
    {

        if ($record->active_release_id === null) {
            if (app()->environment('production') && Schema::hasTable('system_module_release')) {
                throw new InvalidArgumentException("模块 [{$record->name}] 未绑定已审核的不可变制品。");
            }
        } else {
            $this->assertActiveReleaseIntegrity($record, $forceIntegrityCheck);
        }

        $manifestPath = rtrim((string) $record->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json';
        if (! is_file($manifestPath)) {
            throw new InvalidArgumentException("模块 [{$record->name}] 缺少 module.json。");
        }

        ModuleManifest::fromFile($manifestPath);

        return $record;
    }

    private function assertActiveReleaseIntegrity(SystemModule $module, bool $force): void
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
            // Cache outages do not replace a successful fresh verification.
        }
    }

    private function forgetCachedIntegrity(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // The fresh hash result remains authoritative when cache is unavailable.
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
}
