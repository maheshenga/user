<?php

namespace App\Contracts\Modules;

interface ActivationCodeGateway
{
    public function createBatch(string $module, array $payload, ?int $adminId): array;

    public function generateCodes(string $module, int $batchId, int $count, ?int $adminId): array;

    public function redeem(string $module, array $payload, int $userId, string $ip): array;
}
