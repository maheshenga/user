<?php

namespace App\Modules\Host;

use App\Contracts\Modules\InvitationGateway;
use App\User\InviteService;

final class HostInvitationGateway implements InvitationGateway
{
    public function __construct(private readonly InviteService $invites) {}

    public function summary(int $userId): array
    {
        return $this->invites->inviteSummary($userId);
    }

    public function records(int $userId, int $limit = 20): array
    {
        return $this->invites->inviteRecords($userId, $limit);
    }
}
