<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ModuleInstaller
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly ModuleRepository $repository,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleVersionRecorder $versions,
        private readonly ModuleMigrationRunner $migrations,
    ) {}

    public function install(string $name, ?int $actorId = null): void
    {
        $manifest = $this->manager->manifest($name);
        if ($manifest === null) {
            throw new InvalidArgumentException("模块不存在：{$name}");
        }

        $current = $this->repository->installed($name);
        if ($current === null) {
            $this->repository->upsertDiscovered($manifest);
            $current = $this->repository->installed($name);
        }

        $oldState = $current?->status;
        $newState = $this->installTargetState($oldState);

        $this->runLifecycleAction('install', $name, $oldState, $newState, $actorId, function () use ($current, $manifest, $name, $newState): void {
            if ($current !== null && in_array($current->status, ['pending_review', 'rejected'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 必须先通过审核才能安装。");
            }

            $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $name);
            $this->repository->upsertDiscovered($manifest);
            $this->versions->record($manifest);
            $this->importMenus($manifest);
            $this->migrations->runPending($manifest);
            $this->repository->setStatus($name, $newState);
        });
    }

    public function enable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('enable', $name, $oldState, 'enabled', $actorId, function () use ($module, $name): void {
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            $this->reservedPrefixes->assertAllowed((string) $module->admin_prefix, $name);

            if (! in_array($module->status, ['installed', 'disabled'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许启用。");
            }

            $this->repository->setStatus($name, 'enabled');
        });
    }

    public function disable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('disable', $name, $oldState, 'disabled', $actorId, function () use ($module, $name): void {
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            if ($module->status !== 'enabled') {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许禁用。");
            }

            $this->repository->setStatus($name, 'disabled');
        });
    }

    public function uninstallPreserve(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('uninstall', $name, $oldState, 'uninstalled', $actorId, function () use ($module, $name): void {
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            if (! in_array($module->status, ['installed', 'disabled', 'enabled'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许卸载。");
            }

            $this->repository->setStatus($name, 'uninstalled');
        });
    }

    private function importMenus(ModuleManifest $manifest): void
    {
        foreach ($manifest->menus() as $menu) {
            $this->importMenuNode($menu, 0);
        }
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function importMenuNode(array $menu, int $pid): void
    {
        $href = isset($menu['href']) ? (string) $menu['href'] : '';
        $title = (string) ($menu['title'] ?? '');
        $icon = isset($menu['icon']) ? (string) $menu['icon'] : '';
        $children = is_array($menu['children'] ?? null) ? $menu['children'] : [];

        if ($href === '') {
            $existing = DB::table('system_menu')
                ->where('title', $title)
                ->where('pid', $pid)
                ->where(function ($query) {
                    $query->whereNull('href')->orWhere('href', '');
                })
                ->first();
        } else {
            $existing = DB::table('system_menu')->where('href', $href)->first();
        }

        if ($existing !== null) {
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('system_menu')->insertGetId([
                'pid' => $pid,
                'title' => $title,
                'icon' => $icon,
                'href' => $href,
                'target' => '_self',
                'sort' => 0,
                'status' => 1,
                'create_time' => time(),
            ]);
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->importMenuNode($child, $id);
            }
        }
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }

    private function installTargetState(?string $currentState): string
    {
        return match ($currentState) {
            'enabled', 'disabled', 'installed' => $currentState,
            default => 'installed',
        };
    }

    /**
     * @param  callable(): void  $operation
     */
    private function runLifecycleAction(
        string $action,
        string $name,
        ?string $oldState,
        ?string $newState,
        ?int $actorId,
        callable $operation
    ): void {
        try {
            DB::transaction(function () use ($action, $name, $oldState, $newState, $actorId, $operation): void {
                $operation();
                $this->repository->log($action, $name, $oldState, $newState, 'success', null, $actorId);
            });
        } catch (Throwable $exception) {
            $this->repository->setLastError($name, $exception->getMessage());
            $this->repository->log($action, $name, $oldState, $oldState, 'failed', $exception->getMessage(), $actorId);

            throw $exception;
        }

        $this->repository->setLastError($name, null);
        $this->clearCaches();
    }
}
