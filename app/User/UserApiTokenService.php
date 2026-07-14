<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserApiRefreshToken;
use App\Models\UserApiSession;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class UserApiTokenService
{
    public function __construct(private readonly ModuleApiPolicy $modulePolicy) {}

    /**
     * @param  array{device_id?: mixed, device_name?: mixed}  $device
     * @return array<string, mixed>
     */
    public function issue(
        UserAccount $user,
        string $module,
        array $device,
        string $ip,
        string $userAgent
    ): array {
        $this->modulePolicy->assertUserAccess($module, $user);
        $abilities = $this->moduleAbilities($module);
        $deviceId = $this->requiredDeviceValue($device['device_id'] ?? null, '设备标识不能为空。', 128);
        $deviceName = $this->optionalDeviceName($device['device_name'] ?? null);

        return DB::transaction(function () use ($user, $module, $deviceId, $deviceName, $abilities, $ip, $userAgent): array {
            $session = UserApiSession::query()
                ->where('user_id', $user->id)
                ->where('module', $module)
                ->where('device_id', $deviceId)
                ->lockForUpdate()
                ->first();

            if ($session !== null) {
                $this->revokeSessionRecords($session);
            } else {
                $session = new UserApiSession([
                    'user_id' => $user->id,
                    'module' => $module,
                    'device_id' => $deviceId,
                ]);
            }

            $session->forceFill([
                'device_name' => $deviceName,
                'last_ip' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 500),
                'last_used_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $this->issueForSession($user, $session, $abilities);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rotate(string $refreshToken, string $ip, string $userAgent): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new UserApiException('刷新令牌不能为空。', 401, 'refresh_token_missing');
        }

        $result = DB::transaction(function () use ($refreshToken, $ip, $userAgent): array {
            $refresh = UserApiRefreshToken::query()
                ->where('token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if ($refresh === null) {
                return ['error' => new UserApiException('刷新令牌无效。', 401, 'refresh_token_invalid')];
            }

            $session = UserApiSession::query()->lockForUpdate()->find($refresh->session_id);
            if ($session === null) {
                return ['error' => new UserApiException('设备会话不存在。', 401, 'session_missing')];
            }

            if ($refresh->used_at !== null) {
                $this->revokeSessionRecords($session);

                return ['error' => new UserApiException('检测到刷新令牌重复使用，请重新登录。', 401, 'refresh_token_reused')];
            }

            if ($refresh->revoked_at !== null || $session->revoked_at !== null) {
                $this->revokeSessionRecords($session);

                return ['error' => new UserApiException('设备会话已失效，请重新登录。', 401, 'session_revoked')];
            }

            if ($refresh->expires_at === null || ! $refresh->expires_at->isFuture()) {
                $this->revokeSessionRecords($session);

                return ['error' => new UserApiException('刷新令牌已过期，请重新登录。', 401, 'refresh_token_expired')];
            }

            $user = UserAccount::query()->find($session->user_id);
            if ($user === null || ! UserAccountStatus::canLogin((string) $user->status)) {
                $this->revokeSessionRecords($session);

                return ['error' => new UserApiException('账号当前不可登录。', 403, 'account_unavailable')];
            }

            try {
                $this->modulePolicy->assertUserAccess((string) $session->module, $user);
            } catch (UserApiException $exception) {
                $this->revokeSessionRecords($session);

                return ['error' => $exception];
            }

            $refresh->forceFill(['used_at' => now()])->save();
            $this->deleteAccessToken($session->access_token_id);
            $session->forceFill([
                'last_ip' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 500),
                'last_used_at' => now(),
            ])->save();

            return ['tokens' => $this->issueForSession($user, $session, $this->moduleAbilities($session->module))];
        });

        if (isset($result['error']) && $result['error'] instanceof UserApiException) {
            throw $result['error'];
        }

        return $result['tokens'];
    }

    public function revoke(UserAccount $user, ?int $accessTokenId): void
    {
        DB::transaction(function () use ($user, $accessTokenId): void {
            $query = UserApiSession::query()->where('user_id', $user->id);
            if ($accessTokenId !== null) {
                $query->where('access_token_id', $accessTokenId);
            }

            foreach ($query->lockForUpdate()->get() as $session) {
                $this->revokeSessionRecords($session);
            }
        });
    }

    public function revokeAll(UserAccount $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->revoke($user, null);
            $user->tokens()->delete();
        });
    }

    public function revokeModule(string $module): int
    {
        return DB::transaction(function () use ($module): int {
            $sessions = UserApiSession::query()
                ->where('module', $module)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();
            foreach ($sessions as $session) {
                $this->revokeSessionRecords($session);
            }

            return $sessions->count();
        });
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<string, mixed>
     */
    private function issueForSession(UserAccount $user, UserApiSession $session, array $abilities): array
    {
        $accessMinutes = max(1, (int) config('user_api.access_token_minutes', 15));
        $refreshDays = max(1, (int) config('user_api.refresh_token_days', 30));
        $accessExpiresAt = now()->addMinutes($accessMinutes);
        $refreshExpiresAt = now()->addDays($refreshDays);
        $access = $user->createToken(
            $session->module.':'.$session->device_name,
            $abilities,
            $accessExpiresAt
        );
        $refreshToken = 'rt_'.bin2hex(random_bytes(48));

        $session->forceFill([
            'access_token_id' => $access->accessToken->id,
            'last_used_at' => now(),
        ])->save();
        UserApiRefreshToken::query()->create([
            'session_id' => $session->id,
            'token_hash' => hash('sha256', $refreshToken),
            'expires_at' => $refreshExpiresAt,
        ]);

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessMinutes * 60,
            'refresh_expires_in' => $refreshDays * 86400,
            'access_expires_at' => $accessExpiresAt->toIso8601String(),
            'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
            'session_id' => $session->id,
        ];
    }

    private function revokeSessionRecords(UserApiSession $session): void
    {
        $this->deleteAccessToken($session->access_token_id);
        UserApiRefreshToken::query()
            ->where('session_id', $session->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $session->forceFill([
            'access_token_id' => null,
            'revoked_at' => now(),
        ])->save();
    }

    private function deleteAccessToken(mixed $accessTokenId): void
    {
        if ($accessTokenId !== null) {
            PersonalAccessToken::query()->whereKey((int) $accessTokenId)->delete();
        }
    }

    /**
     * @return array<int, string>
     */
    private function moduleAbilities(string $module): array
    {
        return $this->modulePolicy->abilities($module);
    }

    private function requiredDeviceValue(mixed $value, string $message, int $maxLength): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new UserApiException($message, 422, 'device_invalid');
        }

        return $value;
    }

    private function optionalDeviceName(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'Qingyu Desktop' : mb_substr($value, 0, 160);
    }
}
