<?php

namespace App\User;

use App\Http\Services\TriggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UserOpsMenuService
{
    private const PARENT_TITLE = '用户运营';

    private const LEGACY_PARENT_TITLES = [
        'User Operations',
    ];

    private const ENTRIES = [
        ['title' => '运营概览', 'href' => 'user/dashboard/index', 'icon' => 'fa fa-dashboard', 'sort' => 990],
        ['title' => '用户账号', 'href' => 'user/account/index', 'icon' => 'fa fa-user', 'sort' => 980],
        ['title' => '邀请码', 'href' => 'user/invite/index', 'icon' => 'fa fa-share-alt', 'sort' => 970],
        ['title' => '邀请关系', 'href' => 'user/invite/relations', 'icon' => 'fa fa-sitemap', 'sort' => 960],
        ['title' => 'VIP 套餐', 'href' => 'user/vip-plan/index', 'icon' => 'fa fa-diamond', 'sort' => 950],
        ['title' => '激活码', 'href' => 'user/activation-code/index', 'icon' => 'fa fa-ticket', 'sort' => 940],
        ['title' => '激活记录', 'href' => 'user/activation-code/redemptions', 'icon' => 'fa fa-check-square-o', 'sort' => 930],
        ['title' => '余额流水', 'href' => 'user/balance/index', 'icon' => 'fa fa-list-alt', 'sort' => 920],
        ['title' => '分销佣金', 'href' => 'user/commission/index', 'icon' => 'fa fa-money', 'sort' => 910],
        ['title' => '提现审核', 'href' => 'user/withdrawal/index', 'icon' => 'fa fa-credit-card', 'sort' => 900],
        ['title' => '风控事件', 'href' => 'user/risk-event/index', 'icon' => 'fa fa-warning', 'sort' => 890],
        ['title' => '安全日志', 'href' => 'user/security-log/index', 'icon' => 'fa fa-shield', 'sort' => 880],
        ['title' => '通知队列', 'href' => 'user/notification-outbox/index', 'icon' => 'fa fa-envelope', 'sort' => 870],
        ['title' => '设置', 'href' => 'user/settings/index', 'icon' => 'fa fa-cogs', 'sort' => 860],
    ];

    public function sync(): array
    {
        if (! Schema::hasTable('system_menu')) {
            throw new RuntimeException('系统菜单表不存在，请先完成后台菜单数据表迁移。');
        }

        $now = time();
        $parentId = $this->syncParent($now);

        foreach (self::ENTRIES as $entry) {
            $this->syncChild($parentId, $entry, $now);
        }

        $this->cleanupLegacyParents($parentId, $now);
        TriggerService::updateMenu();

        return [
            'parent_id' => $parentId,
            'synced' => count(self::ENTRIES),
        ];
    }

    private function syncParent(int $now): int
    {
        $data = [
            'pid' => 0,
            'title' => self::PARENT_TITLE,
            'icon' => 'fa fa-users',
            'href' => '',
            'target' => '_self',
            'sort' => 990,
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        $parent = DB::table('system_menu')
            ->where('pid', 0)
            ->whereIn('title', array_merge([self::PARENT_TITLE], self::LEGACY_PARENT_TITLES))
            ->where('href', '')
            ->whereNull('delete_time')
            ->orderByRaw('case when title = ? then 0 else 1 end', [self::PARENT_TITLE])
            ->first();

        if ($parent !== null) {
            DB::table('system_menu')->where('id', $parent->id)->update($data);

            return (int) $parent->id;
        }

        return (int) DB::table('system_menu')->insertGetId($data + [
            'create_time' => $now,
        ]);
    }

    private function syncChild(int $parentId, array $entry, int $now): void
    {
        $data = [
            'pid' => $parentId,
            'title' => $entry['title'],
            'icon' => $entry['icon'],
            'href' => $entry['href'],
            'target' => '_self',
            'sort' => $entry['sort'],
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        $child = DB::table('system_menu')
            ->where('href', $entry['href'])
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

    private function cleanupLegacyParents(int $parentId, int $now): void
    {
        DB::table('system_menu')
            ->where('pid', 0)
            ->where('href', '')
            ->whereIn('title', self::LEGACY_PARENT_TITLES)
            ->where('id', '<>', $parentId)
            ->whereNull('delete_time')
            ->update([
                'status' => 0,
                'update_time' => $now,
                'delete_time' => $now,
            ]);
    }
}
