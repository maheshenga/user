<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;

final class ModuleDependencyGraph
{
    private const INSTALLED_STATUSES = ['installed', 'enabled', 'disabled'];

    /**
     * @return array<int, string>
     */
    public function activationOrder(string $module): array
    {
        $nodes = $this->nodes();
        if (! isset($nodes[$module])) {
            throw new InvalidArgumentException("模块未安装：{$module}");
        }

        $order = [];
        $states = [];
        $stack = [];
        $this->visit($module, $nodes, $states, $stack, $order, true);
        foreach ($order as $dependency) {
            if ($dependency !== $module && $nodes[$dependency]['status'] !== 'enabled') {
                throw new InvalidArgumentException("模块依赖尚未启用：{$dependency}");
            }
        }

        return $order;
    }

    public function assertCanDisable(string $module): void
    {
        $nodes = $this->nodes();
        if (! isset($nodes[$module])) {
            throw new InvalidArgumentException("模块未安装：{$module}");
        }

        foreach ($nodes as $name => $node) {
            if ($name !== $module && array_key_exists($module, $node['dependencies'])) {
                throw new InvalidArgumentException("模块 [{$module}] 仍被模块 [{$name}] 依赖，不能停用或卸载。");
            }
        }
    }

    public function assertUpgradeCompatible(ModuleManifest $candidate): void
    {
        $nodes = $this->nodes($candidate);
        $name = $candidate->name();
        $this->assertAcyclic($nodes);
        $this->assertNodeDependencies($name, $nodes);

        foreach ($nodes as $dependentName => $node) {
            if ($dependentName === $name || ! isset($node['dependencies'][$name])) {
                continue;
            }
            if (! $this->constraintMatches($candidate->version(), $node['dependencies'][$name])) {
                throw new InvalidArgumentException(
                    "升级后将不满足模块 [{$dependentName}] 的依赖约束 [{$node['dependencies'][$name]}]。"
                );
            }
        }

        foreach ($nodes as $otherName => $node) {
            if ($otherName === $name) {
                continue;
            }
            if ($this->conflictsWith($nodes[$name], $node)) {
                throw new InvalidArgumentException("候选模块 [{$name}] 与已安装模块 [{$otherName}] 冲突。");
            }
            if ($this->conflictsWith($node, $nodes[$name])) {
                throw new InvalidArgumentException("已安装模块 [{$otherName}] 与候选模块 [{$name}] 冲突。");
            }
        }
    }

    private function assertAcyclic(array $nodes): void
    {
        $states = [];
        $stack = [];
        $order = [];
        foreach (array_keys($nodes) as $name) {
            $this->visit($name, $nodes, $states, $stack, $order, false);
        }
    }

    private function visit(
        string $name,
        array $nodes,
        array &$states,
        array &$stack,
        array &$order,
        bool $validateDependencies
    ): void {
        if (($states[$name] ?? null) === 'done') {
            return;
        }
        if (($states[$name] ?? null) === 'visiting') {
            $cycleStart = array_search($name, $stack, true);
            $cycle = array_slice($stack, $cycleStart === false ? 0 : $cycleStart);
            $cycle[] = $name;
            throw new InvalidArgumentException('模块依赖存在循环：'.implode(' -> ', $cycle));
        }

        $states[$name] = 'visiting';
        $stack[] = $name;
        foreach ($nodes[$name]['dependencies'] as $dependency => $constraint) {
            if (! isset($nodes[$dependency])) {
                if ($validateDependencies) {
                    throw new InvalidArgumentException("模块依赖尚未安装：{$dependency}");
                }

                continue;
            }
            if ($validateDependencies && ! $this->constraintMatches($nodes[$dependency]['version'], $constraint)) {
                throw new InvalidArgumentException(
                    "模块依赖 [{$dependency}] 版本 [{$nodes[$dependency]['version']}] 不满足约束 [{$constraint}]。"
                );
            }
            $this->visit($dependency, $nodes, $states, $stack, $order, $validateDependencies);
        }
        array_pop($stack);
        $states[$name] = 'done';
        $order[] = $name;
    }

    private function assertNodeDependencies(string $name, array $nodes): void
    {
        foreach ($nodes[$name]['dependencies'] as $dependency => $constraint) {
            if (! isset($nodes[$dependency])) {
                throw new InvalidArgumentException("模块依赖尚未安装：{$dependency}");
            }
            if (! $this->constraintMatches($nodes[$dependency]['version'], $constraint)) {
                throw new InvalidArgumentException(
                    "模块依赖 [{$dependency}] 版本 [{$nodes[$dependency]['version']}] 不满足约束 [{$constraint}]。"
                );
            }
        }
    }

    private function conflictsWith(array $source, array $target): bool
    {
        $constraint = $source['conflicts'][$target['name']] ?? null;

        return is_string($constraint)
            && ($constraint === '*' || $this->constraintMatches($target['version'], $constraint));
    }

    private function constraintMatches(string $actual, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '*') {
            return true;
        }
        if (str_starts_with($constraint, '^')) {
            $minimum = $this->normalizeVersion(substr($constraint, 1));
            $parts = array_map('intval', explode('.', $minimum));
            $upper = $parts[0] > 0
                ? ($parts[0] + 1).'.0.0'
                : '0.'.($parts[1] + 1).'.0';

            return version_compare($actual, $minimum, '>=') && version_compare($actual, $upper, '<');
        }
        if (preg_match('/^(>=|<=|>|<|=)\s*(.+)$/', $constraint, $parts) === 1) {
            return version_compare($actual, $parts[2], $parts[1]);
        }

        return version_compare($actual, $constraint, '=');
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', trim($version));

        return implode('.', array_pad(array_slice($parts, 0, 3), 3, '0'));
    }

    private function nodes(?ModuleManifest $candidate = null): array
    {
        $nodes = [];
        foreach (SystemModule::query()->whereIn('status', self::INSTALLED_STATUSES)->get() as $module) {
            $manifest = is_array($module->config_json) ? $module->config_json : [];
            $nodes[(string) $module->name] = $this->node(
                (string) $module->name,
                (string) $module->version,
                (string) $module->status,
                $manifest['dependencies'] ?? [],
                $manifest['conflicts'] ?? []
            );
        }
        if ($candidate !== null) {
            $status = $nodes[$candidate->name()]['status'] ?? 'installed';
            $nodes[$candidate->name()] = $this->node(
                $candidate->name(),
                $candidate->version(),
                $status,
                $candidate->dependencies(),
                $candidate->conflicts()
            );
        }

        return $nodes;
    }

    private function node(
        string $name,
        string $version,
        string $status,
        mixed $dependencies,
        mixed $conflicts
    ): array {
        if (! is_array($dependencies) || ! is_array($conflicts)) {
            throw new InvalidArgumentException("模块 [{$name}] 的依赖或冲突声明无效。");
        }

        return [
            'name' => $name,
            'version' => $version,
            'status' => $status,
            'dependencies' => $this->constraints($name, '依赖', $dependencies),
            'conflicts' => $this->constraints($name, '冲突', $conflicts),
        ];
    }

    private function constraints(string $module, string $label, array $constraints): array
    {
        foreach ($constraints as $name => $constraint) {
            if (
                ! is_string($name)
                || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1
                || ! is_string($constraint)
                || trim($constraint) === ''
            ) {
                throw new InvalidArgumentException("模块 [{$module}] 的{$label}声明无效。");
            }
        }

        return $constraints;
    }
}
