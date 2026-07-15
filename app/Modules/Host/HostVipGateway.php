<?php

namespace App\Modules\Host;

use App\Contracts\Modules\VipGateway;
use App\User\VipService;
use App\Modules\ModuleCapabilityPolicy;
use App\Modules\ModuleIdentity;

final class HostVipGateway implements VipGateway
{
    public function __construct(
        private readonly VipService $vip,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function summary(int $userId): array
    {
        $this->capabilities->authorize('vip:read');

        return $this->vip->summary($userId);
    }

    public function grant(int $userId, int $vipPlanId, string $sourceType, int $sourceId): array
    {
        $identity = $this->capabilities->authorize('vip:write');

        return $this->vip->grant($userId, $vipPlanId, $this->sourceType($identity, $sourceType), $sourceId);
    }

    private function sourceType(ModuleIdentity $identity, string $sourceType): string
    {
        if ($identity->isHost()) {
            return $sourceType;
        }

        $sourceType = preg_replace('/[^a-z0-9._-]+/i', '_', trim($sourceType)) ?: 'grant';

        return mb_substr('module:'.$identity->name.':'.$sourceType, 0, 80);
    }
}
