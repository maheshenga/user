<?php

namespace App\Modules;

use App\Models\SystemModuleMenu;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleMenuSynchronizer
{
    public function sync(ModuleManifest $manifest): void
    {
        DB::transaction(function () use ($manifest): void {
            $seen = [];
            foreach ($manifest->menus() as $index => $menu) {
                if (is_array($menu)) {
                    $this->syncNode($manifest->name(), $menu, 0, 'root', (int) $index, $seen);
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
            if ($href !== '' && DB::table('system_menu')->where('href', $href)->exists()) {
                throw new InvalidArgumentException("模块 [{$module}] 菜单地址 [{$href}] 已被其他菜单占用。");
            }

            $menuId = (int) DB::table('system_menu')->insertGetId($payload + ['create_time' => time()]);
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
                $this->syncNode($module, $child, $menuId, $menuKey, (int) $childIndex, $seen);
            }
        }
    }

    private function menuKey(string $key): string
    {
        return strlen($key) <= 255 ? $key : substr($key, 0, 190).':'.hash('sha256', $key);
    }
}
