<?php

namespace App\Contracts\Modules;

interface InvitationGateway
{
    public function summary(int $userId): array;

    public function records(int $userId, int $limit = 20): array;
}
