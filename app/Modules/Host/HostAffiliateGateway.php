<?php

namespace App\Modules\Host;

use App\Contracts\Modules\AffiliateGateway;
use App\User\AffiliateService;

final class HostAffiliateGateway implements AffiliateGateway
{
    public function __construct(private readonly AffiliateService $affiliate) {}

    public function createForActivationCode(
        int $buyerUserId,
        int $activationCodeId,
        string|float $firstLevelReward,
        string|float $secondLevelReward,
        bool $isCommissionable
    ): array {
        return $this->affiliate->createForActivationCode(
            $buyerUserId,
            $activationCodeId,
            $firstLevelReward,
            $secondLevelReward,
            $isCommissionable
        );
    }
}
