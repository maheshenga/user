<?php

namespace App\Modules\Host;

use App\Contracts\Modules\AuditGateway;
use App\User\UserSecurityLogService;

final class HostAuditGateway implements AuditGateway
{
    public function __construct(private readonly UserSecurityLogService $logs) {}

    public function write(?int $userId, string $event, string $ip, array $metadata = []): void
    {
        $this->logs->write($userId, $event, $ip, $metadata);
    }
}
