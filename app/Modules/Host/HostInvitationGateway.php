<?php

namespace App\Modules\Host;

use App\Contracts\Modules\InvitationGateway;
use App\User\InviteService;
use App\Modules\ModuleCapabilityPolicy;

final class HostInvitationGateway implements InvitationGateway
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function summary(int $userId): array
    {
        $this->capabilities->authorize('invite:read');

        return $this->invites->inviteSummary($userId);
    }

    public function records(int $userId, int $limit = 20): array
    {
        $this->capabilities->authorize('invite:read');

        return $this->invites->inviteRecords($userId, $limit);
    }
}
