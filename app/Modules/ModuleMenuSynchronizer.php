<?php

namespace App\Modules;

use App\Models\SystemModuleMenu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleMenuSynchronizer
{
    public function sync(ModuleManifest $manifest, bool $adoptLegacy = false): void
    {
        DB::transaction(function () use ($manifest, $adoptLegacy): void {
            $seen = [];
            foreach ($manifest->menus() as $index => $menu) {
                if (is_array($menu)) {
                    $this->syncNode(
                        $manifest->name(),
                        $manifest->adminPrefix(),
                        $adoptLegacy,
                        $menu,
                        0,
                        'root',
                        (int) $index,
                        $seen
                    );
                }
            }

            $stale = SystemModuleMenu::query()
                ->where('module', $manifest->name())
                ->when($seen !== [], fn ($query) => $query->whereNotIn('menu_key', $seen))
                ->pluck('menu_id');
            if ($stale->isNotEmpty()) {
                DB::table('system_menu')->whereIn('id', $stale)->update([
                    'status' => 0,
                    'update_time' => time(),
                ]);
            }
        });
    }

    public function hide(string $module): void
    {
        $ids = SystemModuleMenu::query()->where('module', $module)->pluck('menu_id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('system_menu')->whereIn('id', $ids)->update([
            'status' => 0,
            'update_time' => time(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $menu
     * @param  array<int, string>  $seen
     */
    private function syncNode(
        string $module,
        string $adminPrefix,
        bool $adoptLegacy,
        array $menu,
        int $parentId,
        string $parentKey,
        int $index,
        array &$seen
    ): void {
        $title = trim((string) ($menu['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException("模块 [{$module}] 菜单标题不能为空。");
        }

        $href = trim((string) ($menu['href'] ?? ''));
        $segment = trim((string) ($menu['key'] ?? ''));
        if ($segment === '') {
            $segment = $href !== '' ? 'href:'.$href : 'title:'.$title.'#'.$index;
        }
        $menuKey = $this->menuKey($parentKey.'/'.$segment);
        $seen[] = $menuKey;
        $payload = [
            'pid' => $parentId,
            'title' => $title,
            'icon' => (string) ($menu['icon'] ?? ''),
            'href' => $href,
            'target' => (string) ($menu['target'] ?? '_self'),
            'sort' => (int) ($menu['sort'] ?? 0),
            'status' => 1,
            'update_time' => time(),
        ];
        $managedPayload = $payload;
        unset($managedPayload['update_time']);
        $managedHash = hash('sha256', json_encode($managedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $mapping = SystemModuleMenu::query()
            ->where('module', $module)
            ->where('menu_key', $menuKey)
            ->first();

        if ($mapping === null) {
            $menuId = $adoptLegacy
                ? $this->legacyMenuId($module, $adminPrefix, $href, $parentId, $title)
                : null;
            if ($menuId === null) {
                if ($href !== '' && DB::table('system_menu')->where('href', $href)->exists()) {
                    throw new InvalidArgumentException("模块 [{$module}] 菜单地址 [{$href}] 已被其他菜单占用。");
                }

                $menuId = (int) DB::table('system_menu')->insertGetId($payload + ['create_time' => time()]);
            } else {
                DB::table('system_menu')->where('id', $menuId)->update($payload);
            }

            $mapping = SystemModuleMenu::query()->create([
                'module' => $module,
                'menu_id' => $menuId,
                'menu_key' => $menuKey,
                'managed_hash' => $managedHash,
            ]);
        } else {
            $menuId = (int) $mapping->menu_id;
            if ($href !== '' && DB::table('system_menu')->where('href', $href)->where('id', '!=', $menuId)->exists()) {
                throw new InvalidArgumentException("模块 [{$module}] 菜单地址 [{$href}] 已被其他菜单占用。");
            }
            if (DB::table('system_menu')->where('id', $menuId)->exists()) {
                DB::table('system_menu')->where('id', $menuId)->update($payload);
            } else {
                $menuId = (int) DB::table('system_menu')->insertGetId($payload + ['create_time' => time()]);
                $mapping->menu_id = $menuId;
            }
            $mapping->managed_hash = $managedHash;
            $mapping->save();
        }

        $children = is_array($menu['children'] ?? null) ? $menu['children'] : [];
        foreach ($children as $childIndex => $child) {
            if (is_array($child)) {
                $this->syncNode(
                    $module,
                    $adminPrefix,
                    $adoptLegacy,
                    $child,
                    $menuId,
                    $menuKey,
                    (int) $childIndex,
                    $seen
                );
            }
        }
    }

    private function legacyMenuId(
        string $module,
        string $adminPrefix,
        string $href,
        int $parentId,
        string $title
    ): ?int {
        if ($href !== '') {
            if (! str_starts_with($href, $adminPrefix.'/')) {
                return null;
            }

            $ids = DB::table('system_menu')
                ->where('pid', $parentId)
                ->where('href', $href)
                ->pluck('id');
        } elseif ($parentId === 0) {
            $ids = DB::table('system_menu')
                ->where('pid', 0)
                ->where(function ($query): void {
                    $query->whereNull('href')->orWhere('href', '');
                })
                ->pluck('id')
                ->filter(fn (mixed $id): bool => DB::table('system_menu')
                    ->where('pid', (int) $id)
                    ->where('href', 'like', $adminPrefix.'/%')
                    ->exists())
                ->values();
        } else {
            $ids = DB::table('system_menu')
                ->where('pid', $parentId)
                ->where('title', $title)
                ->where(function ($query): void {
                    $query->whereNull('href')->orWhere('href', '');
                })
                ->pluck('id');
        }

        if ($ids->isEmpty()) {
            return null;
        }
        if ($ids->count() !== 1) {
            throw new InvalidArgumentException("模块 [{$module}] 旧菜单归属不唯一，必须人工处理。");
        }

        $menuId = (int) $ids->first();
        if (SystemModuleMenu::query()->where('menu_id', $menuId)->exists()) {
            throw new InvalidArgumentException("模块 [{$module}] 旧菜单已归属于其他模块。");
        }

        return $menuId;
    }

    private function menuKey(string $key): string
    {
        return strlen($key) <= 255 ? $key : substr($key, 0, 190).':'.hash('sha256', $key);
    }
}
