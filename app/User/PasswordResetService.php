<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserPasswordReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PasswordResetService
{
    public function __construct(
        private readonly UserSecurityLogService $securityLogs,
        private readonly PasswordResetNotificationService $notifications,
        private readonly UserPasswordHasher $passwords,
        private readonly UserOpsSettings $settings,
        private readonly UserApiTokenService $apiTokens
    ) {}

    public function requestReset(array $payload, string $ip): array
    {
        [$accountType, $account] = $this->accountTypeAndValue($payload['account'] ?? null);
        if ($account === null) {
            throw new InvalidArgumentException('请填写账号。');
        }

        $expiresMinutes = $this->settings->passwordResetExpiresMinutes();
        $response = [
            'accepted' => true,
            'account_type' => $accountType,
            'account' => $account,
            'delivery' => $this->notifications->publicDelivery($accountType, $account),
            'expires_in' => $expiresMinutes * 60,
        ];
        $user = UserAccount::query()->where($accountType, $account)->first();
        if ($user === null) {
            return $response;
        }

        $token = Str::random(40);
        $code = (string) random_int(100000, 999999);
        $now = time();
        $reset = UserPasswordReset::query()->create([
            'user_id' => $user->id,
            'account_type' => $accountType,
            'account' => $account,
            'token_hash' => $this->hashSecret($token),
            'code_hash' => $this->hashSecret($code),
            'expires_at' => Carbon::now()->addMinutes($expiresMinutes),
            'request_ip' => $ip,
            'attempt_count' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->securityLogs->write((int) $user->id, 'password_reset_requested', $ip, [
            'account_type' => $accountType,
            'account' => $account,
        ]);
        $this->notifications->queue($user, $reset, $token, $code);

        return $response;
    }

    public function resetPassword(array $payload, string $ip): array
    {
        [$accountType, $account] = $this->accountTypeAndValue($payload['account'] ?? null);
        $password = (string) ($payload['password'] ?? '');
        $token = $this->normalizeNullableString($payload['token'] ?? null);
        $code = $this->normalizeNullableString($payload['code'] ?? null);

        if ($account === null) {
            throw new InvalidArgumentException('请填写账号。');
        }

        if ($token === null && $code === null) {
            throw new InvalidArgumentException('请填写重置凭证。');
        }

        if (strlen($password) < 6 || strlen($password) > 72) {
            throw new InvalidArgumentException('密码长度需要在 6 到 72 位之间。');
        }

        $result = DB::transaction(function () use ($accountType, $account, $token, $code, $password, $ip): array {
            $reset = UserPasswordReset::query()
                ->where('account_type', $accountType)
                ->where('account', $account)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($reset === null) {
                throw new InvalidArgumentException('重置凭证无效。');
            }

            if ($reset->used_at !== null) {
                throw new InvalidArgumentException('重置凭证已使用。');
            }

            if ($reset->expires_at === null || Carbon::parse($reset->expires_at)->isPast()) {
                throw new InvalidArgumentException('重置凭证已过期。');
            }

            if ((int) $reset->attempt_count >= 5) {
                throw new InvalidArgumentException('重置尝试次数过多，请重新申请。');
            }

            if (! $this->matchesResetSecret($reset, $token, $code)) {
                $reset->forceFill([
                    'attempt_count' => ((int) $reset->attempt_count) + 1,
                    'update_time' => time(),
                ])->save();

                return ['error' => '重置凭证无效。'];
            }

            $user = UserAccount::query()->whereKey($reset->user_id)->lockForUpdate()->first();
            if ($user === null) {
                throw new InvalidArgumentException('重置凭证无效。');
            }

            $now = time();
            $user->forceFill([
                'password' => $this->passwords->hash($password),
                'update_time' => $now,
            ])->save();

            $reset->forceFill([
                'used_at' => now(),
                'update_time' => $now,
            ])->save();

            $this->apiTokens->revokeAll($user);

            if ((int) session('user.id') === (int) $user->id) {
                session()->forget('user');
            }

            $this->securityLogs->write((int) $user->id, 'password_reset_completed', $ip, [
                'account_type' => $accountType,
                'account' => $account,
            ]);

            return [
                'reset' => true,
                'user_id' => (int) $user->id,
            ];
        });

        if (isset($result['error'])) {
            throw new InvalidArgumentException($result['error']);
        }

        return $result;
    }

    private function matchesResetSecret(UserPasswordReset $reset, ?string $token, ?string $code): bool
    {
        if ($token !== null && hash_equals((string) $reset->token_hash, $this->hashSecret($token))) {
            return true;
        }

        return $code !== null && hash_equals((string) $reset->code_hash, $this->hashSecret($code));
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function accountTypeAndValue(mixed $value): array
    {
        $account = $this->normalizeNullableString($value);
        if ($account === null) {
            return ['mobile', null];
        }

        if (str_contains($account, '@')) {
            return ['email', strtolower($account)];
        }

        return ['mobile', $account];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function hashSecret(string $value): string
    {
        return hash('sha256', $value);
    }
}
