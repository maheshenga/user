<?php

namespace App\Modules\Host;

use App\Contracts\Modules\AffiliateGateway;
use App\User\AffiliateService;
use App\Modules\ModuleCapabilityPolicy;

final class HostAffiliateGateway implements AffiliateGateway
{
    public function __construct(
        private readonly AffiliateService $affiliate,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function createForActivationCode(
        int $buyerUserId,
        int $activationCodeId,
        string|float $firstLevelReward,
        string|float $secondLevelReward,
        bool $isCommissionable
    ): array {
        $this->capabilities->authorize('affiliate:write');

        return $this->affiliate->createForActivationCode(
            $buyerUserId,
            $activationCodeId,
            $firstLevelReward,
            $secondLevelReward,
            $isCommissionable
        );
    }
}
