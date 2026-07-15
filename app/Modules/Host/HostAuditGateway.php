<?php

namespace App\Modules\Host;

use App\Contracts\Modules\AuditGateway;
use App\User\UserSecurityLogService;
use App\Modules\ModuleCapabilityPolicy;

final class HostAuditGateway implements AuditGateway
{
    public function __construct(
        private readonly UserSecurityLogService $logs,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function write(?int $userId, string $event, string $ip, array $metadata = []): void
    {
        $identity = $this->capabilities->authorize('audit:write');
        $this->logs->write($userId, $event, $ip, array_merge($metadata, [
            'module' => $identity->name,
            'request_id' => $identity->requestId,
        ]));
    }
}
