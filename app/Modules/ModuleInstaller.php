<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleInstaller
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly ModuleRepository $repository,
    ) {}

    public function install(string $name, ?int $actorId = null): void
    {
        $manifest = $this->manager->manifest($name);
        if ($manifest === null) {
            throw new InvalidArgumentException("Module not found: {$name}");
        }

        $current = $this->repository->installed($name);
        $oldState = $current?->status;

        $this->repository->upsertDiscovered($manifest);
        $this->importMenus($manifest);
        $this->repository->setStatus($name, 'installed');
        $this->repository->log('install', $name, $oldState, 'installed', 'success', null, $actorId);
        $this->clearCaches();
    }

    public function enable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $oldState = $module->status;

        $this->repository->setStatus($name, 'enabled');
        $this->repository->log('enable', $name, $oldState, 'enabled', 'success', null, $actorId);
        $this->clearCaches();
    }

    public function disable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $oldState = $module->status;

        $this->repository->setStatus($name, 'disabled');
        $this->repository->log('disable', $name, $oldState, 'disabled', 'success', null, $actorId);
        $this->clearCaches();
    }

    public function uninstallPreserve(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $oldState = $module->status;

        $this->repository->setStatus($name, 'uninstalled');
        $this->repository->log('uninstall', $name, $oldState, 'uninstalled', 'success', null, $actorId);
        $this->clearCaches();
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
        $icon = isset($menu['icon']) ? (string) $menu['icon'] : null;
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
}
