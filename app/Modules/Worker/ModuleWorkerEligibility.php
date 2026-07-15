<?php

namespace App\Modules\Worker;

use App\Contracts\Modules\ModuleWorkerClient;
use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

final class ModuleWorkerEligibility
{
    public function __construct(private readonly ModuleWorkerClient $worker) {}

    /**
     * @return array<string, mixed>
     */
    public function assertEligible(SystemModule $module): array
    {
        return $this->assertReleaseEligible($module, $this->release($module));
    }

    /**
     * @return array<string, mixed>
     */
    public function assertReleaseEligible(SystemModule $module, SystemModuleRelease $release): array
    {
        if (
            (string) $release->module !== (string) $module->name
            || ! in_array((string) $release->status, ['approved', 'active', 'superseded'], true)
        ) {
            throw new InvalidArgumentException("模块 [{$module->name}] 的 Worker 制品身份无效。");
        }

        $protocol = trim((string) config('modules.worker.protocol_version', '1.0'));
        $workerContract = $this->workerContract($release);
        if (($workerContract['protocol_version'] ?? null) !== $protocol) {
            throw new InvalidArgumentException("模块 [{$module->name}] 未声明兼容的 Worker 协议版本。");
        }
        $requiredOperations = $this->requiredOperations($workerContract);
        if ($requiredOperations === []) {
            throw new InvalidArgumentException("模块 [{$module->name}] 必须声明 Worker 操作列表。");
        }

        $health = $this->health($module, $release);
        if (($health['status'] ?? null) !== 'ok' || ($health['protocol_version'] ?? null) !== $protocol) {
            throw new InvalidArgumentException("模块 [{$module->name}] 的外部 Worker 协议或健康状态不兼容。");
        }

        $attestation = $health['modules'][$module->name] ?? null;
        if (! is_array($attestation) || ! hash_equals((string) $release->artifact_hash, (string) ($attestation['release_hash'] ?? ''))) {
            throw new InvalidArgumentException("模块 [{$module->name}] 的外部 Worker 未证明当前制品哈希。");
        }

        $supported = is_array($attestation['operations'] ?? null) ? $attestation['operations'] : [];
        foreach ($requiredOperations as $operation) {
            if (! in_array($operation, $supported, true)) {
                throw new InvalidArgumentException("模块 [{$module->name}] 的外部 Worker 不支持操作 [{$operation}]。");
            }
        }

        return $health;
    }

    public function isEligible(SystemModule $module): bool
    {
        try {
            $this->assertEligible($module);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function release(SystemModule $module): SystemModuleRelease
    {
        $releaseId = $module->active_release_id ?? $module->pending_release_id;
        $release = $releaseId === null ? null : SystemModuleRelease::query()->find($releaseId);
        if (
            $release === null
            || (string) $release->module !== (string) $module->name
            || ! in_array((string) $release->status, ['approved', 'active'], true)
        ) {
            throw new InvalidArgumentException("模块 [{$module->name}] 未绑定可供 Worker 执行的已审核制品。");
        }

        return $release;
    }

    /**
     * @return array<string, mixed>
     */
    private function health(SystemModule $module, SystemModuleRelease $release): array
    {
        $cacheKey = 'module-worker:health:'.hash('sha256', implode('|', [
            (string) config('modules.worker.url', ''),
            (string) config('modules.worker.protocol_version', ''),
            (string) config('modules.worker.active_key_id', ''),
            (string) $module->name,
            (string) $release->artifact_hash,
        ]));
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // A cache outage does not replace a fresh Worker attestation.
        }

        $health = $this->worker->health();
        $seconds = max(0, min(300, (int) config('modules.worker.health_cache_seconds', 30)));
        if ($seconds > 0) {
            try {
                Cache::put($cacheKey, $health, $seconds);
            } catch (Throwable) {
                // Eligibility remains valid for this request after a fresh attestation.
            }
        }

        return $health;
    }

    /**
     * @return list<string>
     */
    private function workerContract(SystemModuleRelease $release): array
    {
        $manifest = is_array($release->manifest_json) ? $release->manifest_json : [];

        return is_array($manifest['worker'] ?? null) ? $manifest['worker'] : [];
    }

    /**
     * @param  array<string, mixed>  $workerContract
     * @return list<string>
     */
    private function requiredOperations(array $workerContract): array
    {
        $operations = is_array($workerContract['operations'] ?? null) ? $workerContract['operations'] : [];

        return array_values(array_unique(array_filter(
            $operations,
            static fn (mixed $operation): bool => is_string($operation) && trim($operation) !== ''
        )));
    }
}
