<?php

namespace App\Modules;

use App\Http\Services\TriggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ModuleCenterMenuService
{
    private const PARENT_TITLE = '系统管理';

    private const ENTRY = [
        'title' => '模块管理',
        'href' => 'system/module/index',
        'icon' => 'fa fa-cubes',
        'sort' => 2,
    ];

    public function sync(): array
    {
        if (! Schema::hasTable('system_menu')) {
            throw new RuntimeException('The system_menu table does not exist.');
        }

        $now = time();
        $parentId = $this->syncParent($now);
        $this->syncChild($parentId, $now);

        TriggerService::updateMenu();

        return [
            'parent_id' => $parentId,
            'synced' => 1,
        ];
    }

    private function syncParent(int $now): int
    {
        $data = [
            'pid' => 0,
            'title' => self::PARENT_TITLE,
            'icon' => 'fa fa-cog',
            'href' => '',
            'target' => '_self',
            'sort' => 0,
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        $parent = DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', self::PARENT_TITLE)
            ->where('href', '')
            ->whereNull('delete_time')
            ->first();

        if ($parent !== null) {
            DB::table('system_menu')->where('id', $parent->id)->update($data);

            return (int) $parent->id;
        }

        return (int) DB::table('system_menu')->insertGetId($data + [
            'create_time' => $now,
        ]);
    }

    private function syncChild(int $parentId, int $now): void
    {
        $data = [
            'pid' => $parentId,
            'title' => self::ENTRY['title'],
            'icon' => self::ENTRY['icon'],
            'href' => self::ENTRY['href'],
            'target' => '_self',
            'sort' => self::ENTRY['sort'],
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        $child = DB::table('system_menu')
            ->where('href', self::ENTRY['href'])
            ->whereNull('delete_time')
            ->first();

        if ($child !== null) {
            DB::table('system_menu')->where('id', $child->id)->update($data);

            return;
        }

        DB::table('system_menu')->insert($data + [
            'create_time' => $now,
        ]);
    }
}
