<?php

namespace App\User;

use App\Models\UserAccount;
use Illuminate\Support\Carbon;

final class UserApiProfileService
{
    public function __construct(private readonly VipService $vip) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(UserAccount|int $user): array
    {
        $account = $user instanceof UserAccount ? $user->fresh() : UserAccount::query()->find($user);
        if ($account === null || ! UserAccountStatus::canLogin((string) $account->status)) {
            throw new UserApiException('账号当前不可登录。', 403, 'account_unavailable');
        }

        $publicUser = [
            'id' => (int) $account->id,
            'mobile' => $account->mobile,
            'email' => $account->email,
            'nickname' => $account->nickname,
            'status' => $account->status,
            'source_module' => $account->source_module ?: 'core',
        ];
        $vip = $this->vip->summary((int) $account->id);
        $userInfo = $publicUser + [
            'vip_level' => $vip['vip_level'],
            'vip_expire_at' => $vip['vip_expires_at'],
            'vip_expires_at' => $vip['vip_expires_at'],
            'is_vip' => $vip['active'] ? 1 : 0,
            'is_active' => $vip['active'] ? 1 : 0,
            'member_type' => $vip['active'] ? 'vip' : 'free',
            'daysRemaining' => $this->daysRemaining($vip['vip_expires_at']),
            'points' => 0,
        ];

        return [
            'user' => $publicUser,
            'userInfo' => $userInfo,
            'vip' => $vip,
        ];
    }

    private function daysRemaining(?string $expiresAt): int
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return 0;
        }

        $expiration = Carbon::parse($expiresAt);
        if (! $expiration->isFuture()) {
            return 0;
        }

        return max(1, (int) ceil(Carbon::now()->diffInDays($expiration, false)));
    }
}
