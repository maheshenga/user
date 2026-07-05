<?php

namespace App\User;

use App\Mail\UserPasswordResetMail;
use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

final class NotificationOutboxDispatcher
{
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sent = 0;
        $failed = 0;

        $rows = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                $this->sendOne($row);
                $row->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'last_error' => null,
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'update_time' => time(),
                ])->save();
                $sent++;
            } catch (Throwable $exception) {
                $row->forceFill([
                    'status' => 'pending',
                    'last_error' => substr($exception->getMessage(), 0, 1000),
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'available_at' => now()->addMinutes(5),
                    'update_time' => time(),
                ])->save();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function sendOne(UserNotificationOutbox $row): void
    {
        $payload = $row->payload_json;
        $message = $this->passwordResetMessage($payload);

        if ($row->channel === 'email') {
            Mail::to($row->recipient)->send(new UserPasswordResetMail($row->subject, $message));

            return;
        }

        if ($row->channel === 'sms') {
            Log::info('Password reset SMS notification queued through log driver.', [
                'notification_id' => $row->id,
                'recipient_mask' => $row->recipient_mask,
                'code' => $payload['code'] ?? null,
            ]);

            return;
        }

        throw new InvalidArgumentException('Unsupported notification channel.');
    }

    private function passwordResetMessage(array $payload): string
    {
        return implode("\n", [
            'Your EasyAdmin8 password reset request was received.',
            'Reset token: '.($payload['token'] ?? ''),
            'Reset code: '.($payload['code'] ?? ''),
            'This request expires at: '.($payload['expires_at'] ?? ''),
        ]);
    }
}
