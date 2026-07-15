<?php

namespace App\Contracts\Modules;

interface NotificationGateway
{
    public function enqueue(
        ?int $userId,
        string $channel,
        string $recipient,
        string $subject,
        array $payload = [],
    ): int;
}
