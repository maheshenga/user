<?php

namespace App\Contracts\Modules;

interface VipGateway
{
    public function summary(int $userId): array;

    public function grant(int $userId, int $vipPlanId, string $sourceType, int $sourceId): array;
}
