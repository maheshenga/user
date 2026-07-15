<?php

namespace App\Modules\Host;

use App\Contracts\Modules\ActivationCodeGateway;
use App\User\ActivationCodeService;
use App\Modules\ModuleCapabilityPolicy;

final class HostActivationCodeGateway implements ActivationCodeGateway
{
    public function __construct(
        private readonly ActivationCodeService $codes,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function createBatch(array $payload, ?int $adminId): array
    {
        $identity = $this->capabilities->authorize('activation-code:write');

        return $this->codes->createBatch($payload, $adminId, $identity->name);
    }

    public function generateCodes(int $batchId, int $count, ?int $adminId): array
    {
        $identity = $this->capabilities->authorize('activation-code:write');

        return $this->codes->generateCodes($batchId, $count, $adminId, $identity->name);
    }

    public function redeem(array $payload, int $userId, string $ip): array
    {
        $identity = $this->capabilities->authorize('activation-code:write');

        return $this->codes->redeem($payload, $userId, $ip, $identity->name);
    }
}
