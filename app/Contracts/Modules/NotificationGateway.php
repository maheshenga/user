<?php

namespace App\Contracts\Modules;

interface NotificationGateway
{
    public function enqueue(
        string $module,
        ?int $userId,
        string $channel,
        string $recipient,
        string $subject,
        array $payload = [],
    ): int;
}
