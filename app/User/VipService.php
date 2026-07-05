<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserVipRecord;
use App\Models\VipPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VipService
{
    public function grant(int $userId, int $vipPlanId, string $sourceType, int $sourceId): array
    {
        return DB::transaction(function () use ($userId, $vipPlanId, $sourceType, $sourceId): array {
            $user = UserAccount::query()->lockForUpdate()->find($userId);
            if ($user === null) {
                throw new InvalidArgumentException('User not found.');
            }

            $plan = VipPlan::query()->find($vipPlanId);
            if ($plan === null || $plan->status !== 'active') {
                throw new InvalidArgumentException('VIP plan is not active.');
            }

            $now = Carbon::now();
            $beforeExpiresAt = $user->vip_expires_at === null ? null : Carbon::parse($user->vip_expires_at);
            $startsAt = $beforeExpiresAt !== null && $beforeExpiresAt->greaterThan($now)
                ? $beforeExpiresAt->copy()
                : $now->copy();
            $afterExpiresAt = $startsAt->copy()->addDays((int) $plan->duration_days);
            $vipLevel = max((int) $user->vip_level, (int) $plan->level);
            $timestamp = time();

            $record = UserVipRecord::query()->create([
                'user_id' => $user->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'vip_plan_id' => $plan->id,
                'before_expires_at' => $beforeExpiresAt,
                'after_expires_at' => $afterExpiresAt,
                'duration_days' => (int) $plan->duration_days,
                'status' => 'active',
                'create_time' => $timestamp,
            ]);

            $user->forceFill([
                'vip_level' => $vipLevel,
                'vip_expires_at' => $afterExpiresAt,
                'update_time' => $timestamp,
            ])->save();

            return $this->publicGrant($user->refresh(), $record);
        });
    }

    public function summary(int $userId): array
    {
        $user = UserAccount::query()->find($userId);
        if ($user === null) {
            throw new InvalidArgumentException('User not found.');
        }

        $expiresAt = $user->vip_expires_at === null ? null : Carbon::parse($user->vip_expires_at);

        return [
            'user_id' => (int) $user->id,
            'active' => $expiresAt !== null && $expiresAt->isFuture(),
            'vip_level' => (int) $user->vip_level,
            'vip_expires_at' => $expiresAt?->toDateTimeString(),
            'record_count' => UserVipRecord::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->count(),
        ];
    }

    private function publicGrant(UserAccount $user, UserVipRecord $record): array
    {
        $expiresAt = $user->vip_expires_at === null ? null : Carbon::parse($user->vip_expires_at);

        return [
            'user_id' => (int) $user->id,
            'vip_level' => (int) $user->vip_level,
            'vip_expires_at' => $expiresAt?->toDateTimeString(),
            'vip_record_id' => (int) $record->id,
        ];
    }
}
