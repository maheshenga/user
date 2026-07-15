<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UserAuthService
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly RiskService $risk,
        private readonly UserPasswordHasher $passwords
    ) {}

    public function register(array $payload, string $ip, string $sourceModule = 'core'): array
    {
        $mobile = $this->normalizeNullableString($payload['mobile'] ?? null);
        $email = $this->normalizeEmail($payload['email'] ?? null);
        $password = (string) ($payload['password'] ?? '');
        $inviteCode = $this->normalizeNullableString($payload['invite_code'] ?? null);
        $sourceModule = $this->normalizeSourceModule($sourceModule);

        if ($mobile === null && $email === null) {
            throw new InvalidArgumentException('请填写手机号或邮箱。');
        }

        if (strlen($password) < 6) {
            throw new InvalidArgumentException('密码至少需要 6 位。');
        }

        if ($mobile !== null && UserAccount::query()->where('mobile', $mobile)->exists()) {
            throw new InvalidArgumentException('手机号已存在。');
        }

        if ($email !== null && UserAccount::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('邮箱已存在。');
        }

        try {
            [$user, $defaultInviteCode, $inviteRelation] = DB::transaction(function () use ($mobile, $email, $password, $ip, $inviteCode, $sourceModule): array {
                $now = time();

                $user = UserAccount::query()->create([
                    'mobile' => $mobile,
                    'email' => $email,
                    'password' => $this->passwords->hash($password),
                    'nickname' => $mobile ?? $email,
                    'status' => UserAccountStatus::ACTIVE,
                    'source_module' => $sourceModule,
                    'register_ip' => $ip,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $defaultInviteCode = $this->invites->createDefaultCode($user);
                $inviteRelation = $this->invites->bindRegistration($user, $inviteCode);

                return [$user, $defaultInviteCode, $inviteRelation];
            });
        } catch (QueryException $exception) {
            $duplicateException = $this->duplicateRegistrationException($exception, $mobile, $email);

            if ($duplicateException !== null) {
                throw $duplicateException;
            }

            throw $exception;
        }

        if ($inviteRelation !== null) {
            $this->risk->evaluateInviteRegistration((int) $user->id);
        }

        return [
            'user' => $this->publicUser($user),
            'invite_code' => $this->invites->publicCode($defaultInviteCode),
            'invite_relation' => $this->invites->publicRelation($inviteRelation),
        ];
    }

    public function login(array $payload, string $ip): array
    {
        $result = $this->authenticate($payload, $ip);

        session()->regenerate();
        session(['user' => $result['user']]);

        return $result;
    }

    public function authenticate(array $payload, string $ip): array
    {
        $password = (string) ($payload['password'] ?? '');
        [$loginType, $account] = $this->loginTypeAndAccount($payload['account'] ?? null);

        if ($account === null || $password === '') {
            throw new InvalidArgumentException('请填写账号和密码。');
        }

        $this->ensureLoginNotLocked($account, $loginType, $ip);

        $user = UserAccount::query()
            ->where($loginType, $account)
            ->first();

        if ($user === null || ! $this->passwords->verify($password, $user->password)) {
            $message = '账号或密码错误。';

            $this->writeLoginLog(null, $account, $loginType, $ip, 'failed', $message);

            throw new InvalidArgumentException($message);
        }

        if (! UserAccountStatus::canLogin($user->status)) {
            $message = '账号当前不可登录。';

            $this->writeLoginLog($user, $account, $loginType, $ip, 'failed', $message);

            throw new InvalidArgumentException($message);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'update_time' => time(),
        ])->save();

        $this->writeLoginLog($user, $account, $loginType, $ip, 'success');

        $user->refresh();
        $publicUser = $this->publicUser($user);

        return [
            'user' => $publicUser,
        ];
    }

    public function logout(): void
    {
        session()->forget('user');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = $this->normalizeNullableString($value);

        return $email === null ? null : strtolower($email);
    }

    private function normalizeSourceModule(mixed $value): string
    {
        $sourceModule = $this->normalizeNullableString($value);
        if ($sourceModule === null) {
            return 'core';
        }

        $sourceModule = strtolower($sourceModule);
        if (strlen($sourceModule) > 80 || ! preg_match('/^[a-z0-9._-]+$/', $sourceModule)) {
            throw new InvalidArgumentException('所属模块只能包含字母、数字、点、横线和下划线。');
        }

        return $sourceModule;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function loginTypeAndAccount(mixed $value): array
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

    private function ensureLoginNotLocked(string $account, string $loginType, string $ip): void
    {
        $failedCount = UserLoginLog::query()
            ->where('account', $account)
            ->where('login_type', $loginType)
            ->where('ip', $ip)
            ->where('result', 'failed')
            ->where('create_time', '>=', time() - 900)
            ->count();

        if ($failedCount >= 5) {
            throw new InvalidArgumentException('登录失败次数过多，请 15 分钟后再试。');
        }
    }

    private function writeLoginLog(
        ?UserAccount $user,
        string $account,
        string $loginType,
        string $ip,
        string $result,
        string $errorMessage = ''
    ): void {
        UserLoginLog::query()->create([
            'user_id' => $user?->id,
            'account' => $account,
            'login_type' => $loginType,
            'ip' => $ip,
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'result' => $result,
            'error_message' => $errorMessage,
            'create_time' => time(),
        ]);
    }

    private function duplicateRegistrationException(QueryException $exception, ?string $mobile, ?string $email): ?InvalidArgumentException
    {
        if (! $this->isUniqueConstraintViolation($exception)) {
            return null;
        }

        $message = strtolower($exception->getMessage().' '.implode(' ', $exception->errorInfo ?? []));

        if ($mobile !== null && $this->mentionsUniqueColumn($message, 'mobile')) {
            return new InvalidArgumentException('手机号已存在。', 0, $exception);
        }

        if ($email !== null && $this->mentionsUniqueColumn($message, 'email')) {
            return new InvalidArgumentException('邮箱已存在。', 0, $exception);
        }

        if ($mobile !== null && UserAccount::query()->where('mobile', $mobile)->exists()) {
            return new InvalidArgumentException('手机号已存在。', 0, $exception);
        }

        if ($email !== null && UserAccount::query()->where('email', $email)->exists()) {
            return new InvalidArgumentException('邮箱已存在。', 0, $exception);
        }

        if ($mobile !== null && $email === null) {
            return new InvalidArgumentException('手机号已存在。', 0, $exception);
        }

        if ($email !== null && $mobile === null) {
            return new InvalidArgumentException('邮箱已存在。', 0, $exception);
        }

        return null;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage().' '.implode(' ', $exception->errorInfo ?? []));

        return $exception instanceof UniqueConstraintViolationException
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'integrity constraint violation: 1062');
    }

    private function mentionsUniqueColumn(string $message, string $column): bool
    {
        return str_contains($message, "user_account.{$column}")
            || str_contains($message, "user_account_{$column}_unique")
            || str_contains($message, "user_account_{$column}_index");
    }

    private function publicUser(UserAccount $user): array
    {
        return [
            'id' => $user->id,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'nickname' => $user->nickname,
            'status' => $user->status,
            'source_module' => $user->source_module ?: 'core',
        ];
    }
}
