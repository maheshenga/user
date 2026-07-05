<?php

namespace App\User;

use App\Models\UserSecurityLog;

final class UserSecurityLogService
{
    public function write(?int $userId, string $event, string $ip, array $metadata = []): void
    {
        UserSecurityLog::query()->create([
            'user_id' => $userId,
            'event' => $event,
            'ip' => $ip,
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'metadata_json' => $metadata,
            'create_time' => time(),
        ]);
    }
}
