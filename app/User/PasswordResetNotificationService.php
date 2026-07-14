<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserPasswordReset;

final class PasswordResetNotificationService
{
    public function queue(UserAccount $user, UserPasswordReset $reset, string $token, string $code): array
    {
        $recipient = (string) $reset->account;
        $delivery = $this->publicDelivery((string) $reset->account_type, $recipient);
        $channel = $delivery['channel'];
        $mask = $delivery['recipient_mask'];
        $subject = $channel === 'email' ? 'Reset your EasyAdmin8 password' : 'EasyAdmin8 password reset code';
        $now = time();

        $outbox = UserNotificationOutbox::query()->create([
            'user_id' => $user->id,
            'type' => 'password_reset',
            'channel' => $channel,
            'recipient' => $recipient,
            'recipient_mask' => $mask,
            'subject' => $subject,
            'payload_json' => [
                'password_reset_id' => (int) $reset->id,
                'account_type' => $reset->account_type,
                'token' => $token,
                'code' => $code,
                'expires_at' => $reset->expires_at?->toISOString(),
            ],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return ['notification_id' => (int) $outbox->id] + $delivery;
    }

    /**
     * @return array{channel:string,recipient_mask:string}
     */
    public function publicDelivery(string $accountType, string $account): array
    {
        $channel = $accountType === 'email' ? 'email' : 'sms';

        return [
            'channel' => $channel,
            'recipient_mask' => $channel === 'email'
                ? $this->maskEmail($account)
                : $this->maskMobile($account),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = $local === '' ? '*' : substr($local, 0, 1);

        return $prefix.'***@'.$domain;
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 7) {
            return str_repeat('*', strlen($mobile));
        }

        return substr($mobile, 0, 3).'****'.substr($mobile, -4);
    }
}
