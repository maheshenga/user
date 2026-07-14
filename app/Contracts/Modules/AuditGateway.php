<?php

namespace App\Contracts\Modules;

interface AuditGateway
{
    public function write(?int $userId, string $event, string $ip, array $metadata = []): void;
}
