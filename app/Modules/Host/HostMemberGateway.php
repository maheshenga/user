<?php

namespace App\Modules\Host;

use App\Contracts\Modules\MemberGateway;
use App\User\UserApiProfileService;
use App\Modules\ModuleCapabilityPolicy;

final class HostMemberGateway implements MemberGateway
{
    public function __construct(
        private readonly UserApiProfileService $profiles,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function profile(int $userId): array
    {
        $this->capabilities->authorize('user:read');

        return $this->profiles->payload($userId);
    }
}
