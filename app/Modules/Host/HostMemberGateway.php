<?php

namespace App\Modules\Host;

use App\Contracts\Modules\MemberGateway;
use App\User\UserApiProfileService;

final class HostMemberGateway implements MemberGateway
{
    public function __construct(private readonly UserApiProfileService $profiles) {}

    public function profile(int $userId): array
    {
        return $this->profiles->payload($userId);
    }
}
