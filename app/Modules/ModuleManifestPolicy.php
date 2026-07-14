<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;

final class ModuleManifestPolicy
{
    public function validate(ModuleManifest $manifest): void
    {
        $this->assertType($manifest);
        $this->assertVersion($manifest->version(), '模块版本');
        $this->assertNamespace($manifest->namespace());
        $this->assertConstraint(PHP_VERSION, $manifest->phpConstraint(), 'PHP 版本');
        $this->assertConstraint((string) config('modules.host_version', '8.0.0'), $manifest->coreVersion(), '宿主版本');
        $this->assertStringList($manifest->permissions(), (array) config('modules.allowed_permissions', []), '模块权限');
        $this->assertStringList($manifest->apiAbilities(), (array) config('user_api.allowed_abilities', []), 'API 能力');
        $this->assertApiQuotas($manifest->apiQuotas());
        $this->assertExternalDomains($manifest->externalDomains());
        $this->assertDependencies($manifest);
        $this->assertConflicts($manifest);
    }

    private function assertType(ModuleManifest $manifest): void
    {
        if (! in_array($manifest->type(), (array) config('modules.allowed_types', []), true)) {
            throw new InvalidArgumentException("不支持的模块类型：{$manifest->type()}");
        }
    }

    private function assertVersion(string $version, string $label): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new InvalidArgumentException("{$label}必须使用语义化版本。");
        }
    }

    private function assertNamespace(string $namespace): void
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*$/', $namespace) !== 1) {
            throw new InvalidArgumentException('模块命名空间格式无效。');
        }
    }

    private function assertConstraint(string $actual, ?string $constraint, string $label): void
    {
        if ($constraint === null || trim($constraint) === '' || trim($constraint) === '*') {
            return;
        }

        $constraint = trim($constraint);
        $matches = match (true) {
            str_starts_with($constraint, '^') => $this->matchesCaret($actual, substr($constraint, 1)),
            preg_match('/^(>=|<=|>|<|=)\s*(.+)$/', $constraint, $parts) === 1 => version_compare($actual, $parts[2], $parts[1]),
            default => version_compare($actual, $constraint, '='),
        };

        if (! $matches) {
            throw new InvalidArgumentException("{$label} [{$actual}] 不满足模块要求 [{$constraint}]。");
        }
    }

    private function matchesCaret(string $actual, string $minimum): bool
    {
        $this->assertVersion($this->normalizeVersion($minimum), '版本约束');
        $parts = array_map('intval', explode('.', $this->normalizeVersion($minimum)));
        $upper = $parts[0] > 0
            ? ($parts[0] + 1).'.0.0'
            : '0.'.($parts[1] + 1).'.0';

        return version_compare($actual, $minimum, '>=') && version_compare($actual, $upper, '<');
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);

        return implode('.', array_pad(array_slice($parts, 0, 3), 3, '0'));
    }

    private function assertStringList(array $requested, array $allowed, string $label): void
    {
        foreach ($requested as $value) {
            if (! is_string($value) || $value === '' || ! in_array($value, $allowed, true)) {
                throw new InvalidArgumentException("{$label}未获宿主允许：".(is_scalar($value) ? (string) $value : '[invalid]'));
            }
        }
    }

    private function assertExternalDomains(array $domains): void
    {
        foreach ($domains as $domain) {
            if (! is_string($domain) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain) !== 1) {
                throw new InvalidArgumentException('模块外部域名格式无效。');
            }
        }
    }

    private function assertApiQuotas(array $quotas): void
    {
        foreach ($quotas as $operation => $limit) {
            if (
                ! is_string($operation)
                || preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $operation) !== 1
                || ! is_int($limit)
                || $limit < 1
                || $limit > 1000000
            ) {
                throw new InvalidArgumentException('API 配额声明无效。');
            }
        }
    }

    private function assertDependencies(ModuleManifest $manifest): void
    {
        foreach ($manifest->dependencies() as $name => $constraint) {
            $module = SystemModule::query()->where('name', $name)->first();
            if ($module === null || ! in_array((string) $module->status, ['installed', 'enabled', 'disabled'], true)) {
                throw new InvalidArgumentException("模块依赖尚未安装：{$name}");
            }
            $this->assertConstraint((string) $module->version, (string) $constraint, "模块依赖 {$name}");
        }
    }

    private function assertConflicts(ModuleManifest $manifest): void
    {
        foreach ($manifest->conflicts() as $name => $constraint) {
            $module = SystemModule::query()->where('name', $name)->first();
            if ($module === null || ! in_array((string) $module->status, ['installed', 'enabled', 'disabled'], true)) {
                continue;
            }
            if ($constraint === '*' || $this->constraintMatches((string) $module->version, (string) $constraint)) {
                throw new InvalidArgumentException("模块与已安装模块冲突：{$name}");
            }
        }
    }

    private function constraintMatches(string $actual, string $constraint): bool
    {
        try {
            $this->assertConstraint($actual, $constraint, '冲突版本');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
