<?php

namespace App\Contracts\Modules;

interface ActivationCodeGateway
{
    public function createBatch(array $payload, ?int $adminId): array;

    public function generateCodes(int $batchId, int $count, ?int $adminId): array;

    public function redeem(array $payload, int $userId, string $ip): array;
}
