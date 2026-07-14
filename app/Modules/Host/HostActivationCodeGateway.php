<?php

namespace App\Modules\Host;

use App\Contracts\Modules\ActivationCodeGateway;
use App\User\ActivationCodeService;

final class HostActivationCodeGateway implements ActivationCodeGateway
{
    public function __construct(private readonly ActivationCodeService $codes) {}

    public function createBatch(string $module, array $payload, ?int $adminId): array
    {
        return $this->codes->createBatch($payload, $adminId, $module);
    }

    public function generateCodes(string $module, int $batchId, int $count, ?int $adminId): array
    {
        return $this->codes->generateCodes($batchId, $count, $adminId, $module);
    }

    public function redeem(string $module, array $payload, int $userId, string $ip): array
    {
        return $this->codes->redeem($payload, $userId, $ip, $module);
    }
}
