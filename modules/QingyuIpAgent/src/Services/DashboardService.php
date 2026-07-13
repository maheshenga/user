<?php

namespace Modules\QingyuIpAgent\Services;

use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserAccount;
use App\Models\UserVipRecord;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function summary(): array
    {
        return [
            'member_count' => $this->countIfExists(UserAccount::class, 'user_account'),
            'vip_record_count' => $this->countIfExists(UserVipRecord::class, 'user_vip_record'),
            'activation_batch_count' => $this->countIfExists(ActivationCodeBatch::class, 'activation_code_batch'),
            'redemption_count' => $this->countIfExists(ActivationCodeRedemption::class, 'activation_code_redemption'),
        ];
    }

    private function countIfExists(string $model, string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) $model::query()->count();
    }
}
