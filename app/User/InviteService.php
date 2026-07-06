<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserInviteCode;
use App\Models\UserInviteRelation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InviteService
{
    public function __construct(private readonly UserOpsSettings $settings)
    {
    }

    public function createDefaultCode(UserAccount $user): UserInviteCode
    {
        $existing = UserInviteCode::query()
            ->where('owner_user_id', $user->id)
            ->where('type', 'user')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $now = time();
        $expiresDays = $this->settings->inviteDefaultExpiresDays();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->generateCode();

            if (UserInviteCode::query()->where('code', $code)->exists()) {
                continue;
            }

            return UserInviteCode::query()->create([
                'owner_user_id' => $user->id,
                'code' => $code,
                'type' => 'user',
                'status' => InviteCodeStatus::ACTIVE,
                'max_uses' => $this->settings->inviteDefaultMaxUses(),
                'used_count' => 0,
                'expires_at' => $expiresDays > 0 ? Carbon::now()->addDays($expiresDays) : null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        throw new InvalidArgumentException('无法生成邀请码。');
    }

    public function bindRegistration(UserAccount $user, ?string $inviteCode): ?UserInviteRelation
    {
        $inviteCode = $this->normalizeCode($inviteCode);
        if ($inviteCode === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $inviteCode): UserInviteRelation {
            $code = UserInviteCode::query()
                ->where('code', $inviteCode)
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                throw new InvalidArgumentException('邀请码无效。');
            }

            $this->assertCodeUsable($code);

            $parentId = (int) $code->owner_user_id;
            if ($parentId === (int) $user->id) {
                throw new InvalidArgumentException('不能邀请自己。');
            }

            if (UserInviteRelation::query()->where('user_id', $user->id)->exists()) {
                throw new InvalidArgumentException('邀请关系已存在。');
            }

            $parentRelation = UserInviteRelation::query()
                ->where('user_id', $parentId)
                ->first();
            $grandparentId = $parentRelation?->parent_user_id;
            $levelPath = $parentRelation === null || $parentRelation->level_path === ''
                ? (string) $parentId
                : $parentRelation->level_path.'/'.$parentId;

            if ($this->pathContainsUser($levelPath, (int) $user->id)) {
                throw new InvalidArgumentException('邀请关系不能形成循环。');
            }

            $now = time();
            $relation = UserInviteRelation::query()->create([
                'user_id' => $user->id,
                'parent_user_id' => $parentId,
                'grandparent_user_id' => $grandparentId,
                'invite_code_id' => $code->id,
                'level_path' => $levelPath,
                'bind_type' => 'register',
                'status' => 'active',
                'create_time' => $now,
                'update_time' => $now,
            ]);

            $code->forceFill([
                'used_count' => ((int) $code->used_count) + 1,
                'update_time' => $now,
            ])->save();

            return $relation;
        });
    }

    public function inviteSummary(int $userId): array
    {
        $code = UserInviteCode::query()
            ->where('owner_user_id', $userId)
            ->where('type', 'user')
            ->first();

        return [
            'invite_code' => $code === null ? null : $this->publicCode($code),
            'direct_count' => UserInviteRelation::query()
                ->where('parent_user_id', $userId)
                ->where('status', 'active')
                ->count(),
            'second_level_count' => UserInviteRelation::query()
                ->where('grandparent_user_id', $userId)
                ->where('status', 'active')
                ->count(),
        ];
    }

    public function inviteRecords(int $userId, int $limit = 20): array
    {
        return DB::table('user_invite_relation as relation')
            ->join('user_account as user', 'user.id', '=', 'relation.user_id')
            ->where('relation.parent_user_id', $userId)
            ->where('relation.status', 'active')
            ->whereNull('relation.delete_time')
            ->whereNull('user.delete_time')
            ->orderByDesc('relation.id')
            ->limit($limit)
            ->get([
                'relation.user_id',
                'relation.parent_user_id',
                'relation.grandparent_user_id',
                'relation.level_path',
                'relation.create_time',
                'user.mobile',
                'user.email',
                'user.nickname',
                'user.status',
            ])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    public function publicCode(UserInviteCode $code): array
    {
        return [
            'id' => $code->id,
            'owner_user_id' => $code->owner_user_id,
            'code' => $code->code,
            'type' => $code->type,
            'status' => $code->status,
            'max_uses' => $code->max_uses,
            'used_count' => $code->used_count,
            'expires_at' => $code->expires_at,
        ];
    }

    public function publicRelation(?UserInviteRelation $relation): ?array
    {
        if ($relation === null) {
            return null;
        }

        return [
            'id' => $relation->id,
            'user_id' => $relation->user_id,
            'parent_user_id' => $relation->parent_user_id,
            'grandparent_user_id' => $relation->grandparent_user_id,
            'invite_code_id' => $relation->invite_code_id,
            'level_path' => $relation->level_path,
            'bind_type' => $relation->bind_type,
            'status' => $relation->status,
        ];
    }

    private function assertCodeUsable(UserInviteCode $code): void
    {
        if ($code->status !== InviteCodeStatus::ACTIVE) {
            throw new InvalidArgumentException('邀请码未启用。');
        }

        if ($code->expires_at !== null && Carbon::parse($code->expires_at)->isPast()) {
            throw new InvalidArgumentException('邀请码已过期。');
        }

        if ((int) $code->max_uses > 0 && (int) $code->used_count >= (int) $code->max_uses) {
            throw new InvalidArgumentException('邀请码使用次数已达上限。');
        }
    }

    private function generateCode(): string
    {
        return Str::upper(Str::random(10));
    }

    private function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = Str::upper(trim($code));

        return $code === '' ? null : $code;
    }

    private function pathContainsUser(string $path, int $userId): bool
    {
        return in_array((string) $userId, explode('/', $path), true);
    }
}
