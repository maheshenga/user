<?php

namespace App\Contracts\Modules;

interface AffiliateGateway
{
    public function createForActivationCode(
        int $buyerUserId,
        int $activationCodeId,
        string|float $firstLevelReward,
        string|float $secondLevelReward,
        bool $isCommissionable
    ): array;
}
