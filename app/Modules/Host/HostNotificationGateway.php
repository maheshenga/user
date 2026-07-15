<?php

namespace App\Modules\Host;

use App\Contracts\Modules\NotificationGateway;
use App\Models\UserNotificationOutbox;
use InvalidArgumentException;
use App\Modules\ModuleCapabilityPolicy;

final class HostNotificationGateway implements NotificationGateway
{
    private const SENSITIVE_KEYS = ['password', 'token', 'access_token', 'refresh_token', 'secret', 'activation_code'];

    public function __construct(private readonly ModuleCapabilityPolicy $capabilities) {}

    public function enqueue(
        ?int $userId,
        string $channel,
        string $recipient,
        string $subject,
        array $payload = [],
    ): int {
        $identity = $this->capabilities->authorize('notification:write');
        $module = $identity->name;
        $channel = strtolower(trim($channel));
        $recipient = trim($recipient);
        $subject = trim($subject);
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $module) !== 1) {
            throw new InvalidArgumentException('模块标识无效。');
        }
        if (! in_array($channel, ['email', 'sms'], true) || $recipient === '' || strlen($recipient) > 180) {
            throw new InvalidArgumentException('通知接收方无效。');
        }
        $this->assertPayloadSafe($payload);
        $now = time();
        $row = UserNotificationOutbox::query()->create([
            'user_id' => $userId,
            'type' => substr('module:'.$module, 0, 80),
            'channel' => $channel,
            'recipient' => $recipient,
            'recipient_mask' => $this->maskRecipient($recipient, $channel),
            'subject' => mb_substr($subject, 0, 180),
            'payload_json' => array_merge($payload, [
                'module' => $module,
                'request_id' => $identity->requestId,
            ]),
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return (int) $row->id;
    }

    private function assertPayloadSafe(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                throw new InvalidArgumentException('通知内容不能包含敏感凭证。');
            }
            if (is_array($value)) {
                $this->assertPayloadSafe($value);
            }
        }
    }

    private function maskRecipient(string $recipient, string $channel): string
    {
        if ($channel === 'email' && str_contains($recipient, '@')) {
            [$name, $domain] = explode('@', $recipient, 2);

            return substr($name, 0, 1).'***@'.$domain;
        }

        return strlen($recipient) < 7 ? '***' : substr($recipient, 0, 3).'****'.substr($recipient, -4);
    }
}
