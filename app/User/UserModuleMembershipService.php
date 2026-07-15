<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserModuleMembership;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UserModuleMembershipService
{
    public function grant(
        int $userId,
        string $module,
        string $joinSource,
        ?int $actorId = null
    ): UserModuleMembership {
        $module = $this->normalizeModule($module);
        $joinSource = $this->normalizeJoinSource($joinSource);
        $this->assertUserExists($userId);

        return DB::transaction(function () use ($userId, $module, $joinSource, $actorId): UserModuleMembership {
            $now = now();
            DB::table('user_module_membership')->insertOrIgnore([
                'user_id' => $userId,
                'module' => $module,
                'status' => 'active',
                'join_source' => $joinSource,
                'granted_by' => $actorId,
                'joined_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $membership = UserModuleMembership::query()
                ->where('user_id', $userId)
                ->where('module', $module)
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->status !== 'active') {
                $membership->forceFill([
                    'status' => 'active',
                    'join_source' => $joinSource,
                    'granted_by' => $actorId,
                    'joined_at' => $now,
                    'revoked_at' => null,
                ])->save();
            }

            return $membership->refresh();
        });
    }

    public function join(int $userId, string $module, string $joinSource): UserModuleMembership
    {
        $module = $this->normalizeModule($module);
        $joinSource = $this->normalizeJoinSource($joinSource);
        $this->assertUserExists($userId);

        return DB::transaction(function () use ($userId, $module, $joinSource): UserModuleMembership {
            $membership = UserModuleMembership::query()
                ->where('user_id', $userId)
                ->where('module', $module)
                ->lockForUpdate()
                ->first();
            if ($membership !== null) {
                if ($membership->status !== 'active' || $membership->revoked_at !== null) {
                    throw new UserApiException('当前模块会员关系已被撤销。', 403, 'module_membership_revoked');
                }

                return $membership;
            }

            $now = now();
            DB::table('user_module_membership')->insertOrIgnore([
                'user_id' => $userId,
                'module' => $module,
                'status' => 'active',
                'join_source' => $joinSource,
                'granted_by' => null,
                'joined_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $membership = UserModuleMembership::query()
                ->where('user_id', $userId)
                ->where('module', $module)
                ->lockForUpdate()
                ->firstOrFail();
            if ($membership->status !== 'active' || $membership->revoked_at !== null) {
                throw new UserApiException('当前模块会员关系已被撤销。', 403, 'module_membership_revoked');
            }

            return $membership;
        });
    }

    public function assertActive(int $userId, string $module): UserModuleMembership
    {
        $membership = UserModuleMembership::query()
            ->where('user_id', $userId)
            ->where('module', $this->normalizeModule($module))
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            throw new UserApiException('账号尚未加入当前模块。', 403, 'module_membership_required');
        }

        return $membership;
    }

    public function revoke(int $userId, string $module): bool
    {
        return DB::transaction(function () use ($userId, $module): bool {
            $membership = UserModuleMembership::query()
                ->where('user_id', $userId)
                ->where('module', $this->normalizeModule($module))
                ->lockForUpdate()
                ->first();
            if ($membership === null || $membership->status === 'revoked') {
                return false;
            }

            $membership->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            return true;
        });
    }

    private function normalizeModule(string $module): string
    {
        $module = strtolower(trim($module));
        if (strlen($module) > 80 || preg_match('/^[a-z][a-z0-9._-]*$/', $module) !== 1) {
            throw new InvalidArgumentException('模块标识无效。');
        }

        return $module;
    }

    private function normalizeJoinSource(string $joinSource): string
    {
        $joinSource = trim($joinSource);
        if ($joinSource === '' || strlen($joinSource) > 80) {
            throw new InvalidArgumentException('模块加入来源无效。');
        }

        return $joinSource;
    }

    private function assertUserExists(int $userId): void
    {
        if (! UserAccount::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException('用户不存在。');
        }
    }
}
