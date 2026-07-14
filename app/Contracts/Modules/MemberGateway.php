<?php

namespace App\Contracts\Modules;

interface MemberGateway
{
    public function profile(int $userId): array;
}
