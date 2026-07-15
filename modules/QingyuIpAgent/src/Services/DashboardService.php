<?php

namespace Modules\QingyuIpAgent\Services;

use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserModuleMembership;
use App\Models\UserVipRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function summary(): array
    {
        return [
            'member_count' => $this->memberCount(),
            'vip_record_count' => $this->vipRecordCount(),
            'activation_batch_count' => $this->ownedCount(ActivationCodeBatch::class, 'activation_code_batch'),
            'redemption_count' => $this->ownedCount(ActivationCodeRedemption::class, 'activation_code_redemption'),
        ];
    }

    private function memberCount(): int
    {
        if (! Schema::hasTable('user_module_membership')) {
            return 0;
        }

        return (int) $this->memberships()->count();
    }

    private function vipRecordCount(): int
    {
        if (! Schema::hasTable('user_vip_record') || ! Schema::hasTable('user_module_membership')) {
            return 0;
        }

        return (int) UserVipRecord::query()
            ->whereIn('user_id', $this->memberships()->select('user_id'))
            ->count();
    }

    private function memberships(): Builder
    {
        return UserModuleMembership::query()
            ->where('module', 'qingyu_ip_agent')
            ->where('status', 'active')
            ->whereNull('revoked_at');
    }

    private function ownedCount(string $model, string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) $model::query()->where('owner_module', 'qingyu_ip_agent')->count();
    }
}
