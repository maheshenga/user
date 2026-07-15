<?php

namespace App\Modules;

use App\Models\SystemModule;

final class ModuleExecutionContext
{
    /** @var list<ModuleIdentity> */
    private array $stack = [];

    public function run(SystemModule $module, string $requestId, callable $callback): mixed
    {
        $manifest = is_array($module->config_json) ? $module->config_json : [];
        $permissions = array_values(array_unique(array_filter(
            is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [],
            static fn (mixed $permission): bool => is_string($permission) && $permission !== ''
        )));
        $identity = new ModuleIdentity(
            (string) $module->name,
            $module->active_release_id === null ? null : (int) $module->active_release_id,
            (string) ($module->trust_level ?: $module->type),
            $permissions,
            $this->normalizeRequestId($requestId),
        );

        return $this->withIdentity($identity, $callback);
    }

    public function runAsHost(callable $callback): mixed
    {
        return $this->withIdentity(
            new ModuleIdentity('core', null, 'core', ['*'], 'host', true),
            $callback
        );
    }

    public function requireCurrent(): ModuleIdentity
    {
        $identity = end($this->stack);
        if (! $identity instanceof ModuleIdentity) {
            throw new ModuleApiException('模块调用上下文不存在。', 403, 'module_context_missing');
        }

        return $identity;
    }

    private function withIdentity(ModuleIdentity $identity, callable $callback): mixed
    {
        $this->stack[] = $identity;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    private function normalizeRequestId(string $requestId): string
    {
        $requestId = trim($requestId);

        return $requestId === '' ? 'module-'.bin2hex(random_bytes(12)) : mb_substr($requestId, 0, 80);
    }
}
