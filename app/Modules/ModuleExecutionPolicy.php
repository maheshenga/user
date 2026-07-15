<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;

final class ModuleExecutionPolicy
{
    public function isInProcessAllowed(SystemModule $module): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $configured = config('modules.production_in_process_trust_levels', []);
        $allowed = is_array($configured)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $level): string => is_string($level) ? strtolower(trim($level)) : '',
                $configured
            ))))
            : [];

        return in_array($this->trustLevel($module), $allowed, true);
    }

    public function assertInProcessAllowed(SystemModule $module): void
    {
        if ($this->isInProcessAllowed($module)) {
            return;
        }

        $name = (string) $module->name;
        $trustLevel = $this->trustLevel($module);

        throw new InvalidArgumentException(
            "模块 [{$name}] 的信任级别 [{$trustLevel}] 不允许在生产环境主进程内运行；请使用独立模块执行服务。"
        );
    }

    private function trustLevel(SystemModule $module): string
    {
        $trustLevel = strtolower(trim((string) $module->trust_level));

        return $trustLevel !== '' ? $trustLevel : strtolower(trim((string) $module->type));
    }
}
