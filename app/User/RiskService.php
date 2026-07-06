<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserInviteRelation;
use App\Models\UserRiskEvent;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class RiskService
{
    public function __construct(private readonly UserOpsSettings $settings)
    {
    }

    public function evaluateInviteRegistration(int $userId): array
    {
        $relation = UserInviteRelation::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if ($relation === null) {
            return [];
        }

        $user = UserAccount::query()->find($userId);
        if ($user === null || empty($user->register_ip)) {
            return [];
        }

        $threshold = $this->settings->riskInviteBurstThreshold();
        $since = time() - ($this->settings->riskInviteBurstWindowHours() * 3600);
        $siblingUserIds = UserInviteRelation::query()
            ->where('parent_user_id', $relation->parent_user_id)
            ->where('status', 'active')
            ->where('create_time', '>=', $since)
            ->pluck('user_id');

        $sameIpCount = UserAccount::query()
            ->whereIn('id', $siblingUserIds)
            ->where('register_ip', $user->register_ip)
            ->count();

        if ($sameIpCount < $threshold) {
            return [];
        }

        $event = UserRiskEvent::query()->firstOrCreate([
            'event_type' => 'invite_burst',
            'source_type' => 'user_invite_relation',
            'source_id' => $relation->id,
            'status' => 'open',
        ], [
            'user_id' => $userId,
            'category' => 'invite',
            'severity' => 'medium',
            'ip' => $user->register_ip,
            'detail_json' => [
                'parent_user_id' => (int) $relation->parent_user_id,
                'same_ip_count' => $sameIpCount,
                'threshold' => $threshold,
            ],
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [$this->publicEvent($event)];
    }

    public function recordActivationFailure(int $userId, string $ip, string $reason): array
    {
        $now = time();
        $recentCount = UserRiskEvent::query()
            ->where('user_id', $userId)
            ->where('category', 'activation_code')
            ->where('event_type', 'activation_code_failed')
            ->where('ip', $ip)
            ->where('create_time', '>=', $now - ($this->settings->riskActivationFailureWindowMinutes() * 60))
            ->count();
        $threshold = $this->settings->riskActivationFailureThreshold();

        $event = UserRiskEvent::query()->create([
            'user_id' => $userId,
            'category' => 'activation_code',
            'event_type' => 'activation_code_failed',
            'severity' => $recentCount + 1 >= $threshold ? 'medium' : 'low',
            'source_type' => 'activation_code_redemption',
            'source_id' => null,
            'ip' => $ip,
            'status' => 'open',
            'detail_json' => [
                'reason' => $reason,
                'recent_failure_count' => $recentCount + 1,
                'threshold' => $threshold,
            ],
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->publicEvent($event);
    }

    public function review(int $eventId, string $status, int $adminId): array
    {
        if (! in_array($status, ['reviewed', 'ignored'], true)) {
            throw new InvalidArgumentException('风控事件状态无效。');
        }

        if ($adminId <= 0) {
            throw new InvalidArgumentException('管理员 ID 不能为空。');
        }

        $event = UserRiskEvent::query()->find($eventId);
        if ($event === null) {
            throw new InvalidArgumentException('风控事件不存在。');
        }

        $event->forceFill([
            'status' => $status,
            'review_admin_id' => $adminId,
            'reviewed_at' => Carbon::now(),
            'update_time' => time(),
        ])->save();

        return $this->publicEvent($event->refresh());
    }

    private function publicEvent(UserRiskEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'user_id' => $event->user_id === null ? null : (int) $event->user_id,
            'category' => $event->category,
            'event_type' => $event->event_type,
            'severity' => $event->severity,
            'source_type' => $event->source_type,
            'source_id' => $event->source_id === null ? null : (int) $event->source_id,
            'ip' => $event->ip,
            'status' => $event->status,
            'detail_json' => $event->detail_json,
            'review_admin_id' => $event->review_admin_id === null ? null : (int) $event->review_admin_id,
            'reviewed_at' => $event->reviewed_at,
        ];
    }
}
