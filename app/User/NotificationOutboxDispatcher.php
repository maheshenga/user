<?php

namespace App\User;

use App\Mail\UserNotificationMail;
use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class NotificationOutboxDispatcher
{
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $recovered = $this->recoverStaleClaims();
        $lockToken = (string) Str::uuid();
        $claimedIds = $this->claim($limit, $lockToken);
        $sent = 0;
        $failed = 0;
        $dead = 0;

        $rows = UserNotificationOutbox::query()
            ->whereIn('id', $claimedIds)
            ->where('status', 'processing')
            ->where('lock_token', $lockToken)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            try {
                $this->sendOne($row);
                if (! $this->ownsClaim((int) $row->id, $lockToken)) {
                    continue;
                }
                $payload = $row->payload_json;
                unset($payload['token'], $payload['code']);
                $row->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'last_error' => null,
                    'payload_json' => $payload,
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'locked_at' => null,
                    'lock_token' => null,
                    'failed_at' => null,
                    'update_time' => time(),
                ])->save();
                $sent++;
            } catch (Throwable $exception) {
                if (! $this->ownsClaim((int) $row->id, $lockToken)) {
                    continue;
                }
                $attempts = ((int) $row->attempt_count) + 1;
                $isDead = $attempts >= $this->maxAttempts();
                $row->forceFill([
                    'status' => $isDead ? 'failed' : 'pending',
                    'last_error' => substr($exception->getMessage(), 0, 1000),
                    'attempt_count' => $attempts,
                    'available_at' => $isDead ? null : now()->addMinutes($this->retryMinutes()),
                    'locked_at' => null,
                    'lock_token' => null,
                    'failed_at' => $isDead ? now() : null,
                    'update_time' => time(),
                ])->save();
                $failed++;
                if ($isDead) {
                    $dead++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'dead' => $dead, 'recovered' => $recovered];
    }

    private function sendOne(UserNotificationOutbox $row): void
    {
        $payload = $row->payload_json;
        $message = $this->message($row, $payload);

        if ($row->channel === 'email') {
            Mail::to($row->recipient)->send(new UserNotificationMail($row->subject, $message));

            return;
        }

        if ($row->channel === 'sms') {
            throw new InvalidArgumentException('SMS provider is not configured.');
        }

        throw new InvalidArgumentException('Unsupported notification channel.');
    }

    private function message(UserNotificationOutbox $row, array $payload): string
    {
        if ($row->type === 'password_reset') {
            return $this->passwordResetMessage($payload);
        }

        if (str_starts_with((string) $row->type, 'module:')) {
            $message = $payload['message'] ?? $payload['body'] ?? null;
            if (! is_scalar($message) || trim((string) $message) === '') {
                throw new InvalidArgumentException('Module notification message is missing.');
            }

            return trim((string) $message);
        }

        throw new InvalidArgumentException('Unsupported notification type.');
    }

    private function passwordResetMessage(array $payload): string
    {
        return implode("\n", [
            '我们收到了您的密码重置请求。',
            '重置令牌：'.($payload['token'] ?? ''),
            '重置验证码：'.($payload['code'] ?? ''),
            '有效期至：'.($payload['expires_at'] ?? ''),
        ]);
    }

    private function claim(int $limit, string $lockToken): array
    {
        return DB::transaction(function () use ($limit, $lockToken): array {
            $rows = UserNotificationOutbox::query()
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();
            $ids = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($ids !== []) {
                UserNotificationOutbox::query()
                    ->whereIn('id', $ids)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'processing',
                        'lock_token' => $lockToken,
                        'locked_at' => now(),
                        'update_time' => time(),
                    ]);
            }

            return $ids;
        });
    }

    private function recoverStaleClaims(): int
    {
        return UserNotificationOutbox::query()
            ->where('status', 'processing')
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subSeconds($this->leaseSeconds()));
            })
            ->update([
                'status' => 'pending',
                'lock_token' => null,
                'locked_at' => null,
                'available_at' => now(),
                'update_time' => time(),
            ]);
    }

    private function ownsClaim(int $id, string $lockToken): bool
    {
        return UserNotificationOutbox::query()
            ->whereKey($id)
            ->where('status', 'processing')
            ->where('lock_token', $lockToken)
            ->exists();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('user_notifications.max_attempts', 5));
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('user_notifications.lease_seconds', 300));
    }

    private function retryMinutes(): int
    {
        return max(1, (int) config('user_notifications.retry_minutes', 5));
    }
}
