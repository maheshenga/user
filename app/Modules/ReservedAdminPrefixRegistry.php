<?php

namespace App\Modules;

use InvalidArgumentException;

final class ReservedAdminPrefixRegistry
{
    /**
     * @var array<int, string>|null
     */
    private ?array $prefixes = null;

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $prefixes = [];
        $adminControllersPath = app_path('Http/Controllers/admin');
        if (is_dir($adminControllersPath)) {
            foreach (scandir($adminControllersPath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $adminControllersPath.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($path)) {
                    $prefixes[] = $this->normalize($entry);
                }
            }
        }

        foreach ((array) config('modules.reserved_admin_prefixes', []) as $prefix) {
            if (is_string($prefix)) {
                $prefixes[] = $this->normalize($prefix);
            }
        }

        $prefixes = array_values(array_unique(array_filter($prefixes)));
        sort($prefixes);

        return $this->prefixes = $prefixes;
    }

    public function isReserved(string $prefix): bool
    {
        return in_array($this->normalize($prefix), $this->all(), true);
    }

    public function assertAllowed(string $prefix, ?string $moduleName = null): void
    {
        if (! $this->isReserved($prefix)) {
            return;
        }

        $message = $moduleName === null
            ? "保留的后台前缀 [{$prefix}] 不能被模块使用。"
            : "模块 [{$moduleName}] 不能使用保留的后台前缀 [{$prefix}]，该前缀已被内置后台路由占用。";

        throw new InvalidArgumentException($message);
    }

    private function normalize(string $prefix): string
    {
        return strtolower(trim(str_replace('\\', '/', $prefix), '/'));
    }
}
