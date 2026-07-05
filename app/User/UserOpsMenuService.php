<?php

namespace App\User;

use App\Http\Services\TriggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UserOpsMenuService
{
    private const PARENT_TITLE = 'User Operations';

    private const ENTRIES = [
        ['title' => 'Overview', 'href' => 'user/dashboard/index', 'icon' => 'fa fa-dashboard', 'sort' => 990],
        ['title' => 'User Accounts', 'href' => 'user/account/index', 'icon' => 'fa fa-user', 'sort' => 980],
        ['title' => 'Invite Codes', 'href' => 'user/invite/index', 'icon' => 'fa fa-share-alt', 'sort' => 970],
        ['title' => 'Invite Relations', 'href' => 'user/invite/relations', 'icon' => 'fa fa-sitemap', 'sort' => 960],
        ['title' => 'VIP Plans', 'href' => 'user/vip-plan/index', 'icon' => 'fa fa-diamond', 'sort' => 950],
        ['title' => 'Activation Codes', 'href' => 'user/activation-code/index', 'icon' => 'fa fa-ticket', 'sort' => 940],
        ['title' => 'Activation Redemptions', 'href' => 'user/activation-code/redemptions', 'icon' => 'fa fa-check-square-o', 'sort' => 930],
        ['title' => 'Balance Ledger', 'href' => 'user/balance/index', 'icon' => 'fa fa-list-alt', 'sort' => 920],
        ['title' => 'Affiliate Commissions', 'href' => 'user/commission/index', 'icon' => 'fa fa-money', 'sort' => 910],
        ['title' => 'Withdrawal Review', 'href' => 'user/withdrawal/index', 'icon' => 'fa fa-credit-card', 'sort' => 900],
        ['title' => 'Risk Events', 'href' => 'user/risk-event/index', 'icon' => 'fa fa-warning', 'sort' => 890],
        ['title' => 'Security Logs', 'href' => 'user/security-log/index', 'icon' => 'fa fa-shield', 'sort' => 880],
        ['title' => 'Notification Outbox', 'href' => 'user/notification-outbox/index', 'icon' => 'fa fa-envelope', 'sort' => 870],
    ];

    public function sync(): array
    {
        if (! Schema::hasTable('system_menu')) {
            throw new RuntimeException('The system_menu table does not exist.');
        }

        $now = time();
        $parentId = $this->syncParent($now);

        foreach (self::ENTRIES as $entry) {
            $this->syncChild($parentId, $entry, $now);
        }

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
            ->where('title', self::PARENT_TITLE)
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
}
