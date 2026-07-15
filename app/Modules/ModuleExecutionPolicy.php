<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use App\Modules\Worker\ModuleWorkerEligibility;
use InvalidArgumentException;

final class ModuleExecutionPolicy
{
    private const EXTERNAL_ONLY_TRUST_LEVELS = ['partner', 'community'];

    public function __construct(private readonly ModuleWorkerEligibility $worker) {}

    public function isInProcessAllowed(SystemModule $module): bool
    {
        return $this->isTrustLevelInProcessAllowed($this->trustLevel($module));
    }

    public function isReleaseInProcessAllowed(SystemModule $module, SystemModuleRelease $release): bool
    {
        $trustLevel = strtolower(trim((string) $release->trust_level));

        return $this->isTrustLevelInProcessAllowed(
            $trustLevel !== '' ? $trustLevel : $this->trustLevel($module)
        );
    }

    public function isExecutionInProcessAllowed(SystemModule $module): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $releaseId = $module->active_release_id ?? $module->pending_release_id;
        if ($releaseId === null) {
            return $this->isInProcessAllowed($module);
        }

        $release = SystemModuleRelease::query()->find($releaseId);
        if (
            $release === null
            || (string) $release->module !== (string) $module->name
            || ! in_array((string) $release->status, ['approved', 'active'], true)
        ) {
            return false;
        }

        return $this->isReleaseInProcessAllowed($module, $release);
    }

    private function isTrustLevelInProcessAllowed(string $trustLevel): bool
    {
        if (! app()->environment('production')) {
            return true;
        }
        if (in_array($trustLevel, self::EXTERNAL_ONLY_TRUST_LEVELS, true)) {
            return false;
        }

        $configured = config('modules.production_in_process_trust_levels', []);
        $allowed = is_array($configured)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $level): string => is_string($level) ? strtolower(trim($level)) : '',
                $configured
            ))))
            : [];

        return in_array($trustLevel, $allowed, true);
    }

    public function assertInProcessAllowed(SystemModule $module): void
    {
        if ($this->isExecutionInProcessAllowed($module)) {
            return;
        }

        $name = (string) $module->name;
        $trustLevel = $this->trustLevel($module);

        throw new InvalidArgumentException(
            "模块 [{$name}] 的信任级别 [{$trustLevel}] 不允许在生产环境主进程内运行；请使用独立模块执行服务。"
        );
    }

    public function assertExecutionAllowed(SystemModule $module): void
    {
        if ($this->isExecutionInProcessAllowed($module)) {
            return;
        }

        $this->worker->assertEligible($module);
    }

    public function assertReleaseExecutionAllowed(SystemModule $module, SystemModuleRelease $release): void
    {
        if ($this->isReleaseInProcessAllowed($module, $release)) {
            return;
        }

        $this->worker->assertReleaseEligible($module, $release);
    }

    private function trustLevel(SystemModule $module): string
    {
        $trustLevel = strtolower(trim((string) $module->trust_level));

        return $trustLevel !== '' ? $trustLevel : strtolower(trim((string) $module->type));
    }
}
