<?php

namespace App\Modules\Host;

use App\Contracts\Modules\VipGateway;
use App\User\VipService;

final class HostVipGateway implements VipGateway
{
    public function __construct(private readonly VipService $vip) {}

    public function summary(int $userId): array
    {
        return $this->vip->summary($userId);
    }

    public function grant(int $userId, int $vipPlanId, string $sourceType, int $sourceId): array
    {
        return $this->vip->grant($userId, $vipPlanId, $sourceType, $sourceId);
    }
}
