<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class UserAuthService
{
    public function register(array $payload, string $ip): array
    {
        $mobile = $this->normalizeNullableString($payload['mobile'] ?? null);
        $email = $this->normalizeEmail($payload['email'] ?? null);
        $password = (string) ($payload['password'] ?? '');

        if ($mobile === null && $email === null) {
            throw new InvalidArgumentException('Mobile or email is required.');
        }

        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Password must be at least 6 characters.');
        }

        if ($mobile !== null && UserAccount::query()->where('mobile', $mobile)->exists()) {
            throw new InvalidArgumentException('Mobile already exists.');
        }

        if ($email !== null && UserAccount::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('Email already exists.');
        }

        try {
            $user = DB::transaction(function () use ($mobile, $email, $password, $ip): UserAccount {
                $now = time();

                return UserAccount::query()->create([
                    'mobile' => $mobile,
                    'email' => $email,
                    'password' => $password,
                    'nickname' => $mobile ?? $email,
                    'status' => UserAccountStatus::ACTIVE,
                    'register_ip' => $ip,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            });
        } catch (QueryException $exception) {
            $duplicateException = $this->duplicateRegistrationException($exception, $mobile, $email);

            if ($duplicateException !== null) {
                throw $duplicateException;
            }

            throw $exception;
        }

        return [
            'user' => $this->publicUser($user),
        ];
    }

    public function login(array $payload, string $ip): array
    {
        $password = (string) ($payload['password'] ?? '');
        [$loginType, $account] = $this->loginTypeAndAccount($payload['account'] ?? null);

        if ($account === null || $password === '') {
            throw new InvalidArgumentException('Account and password are required.');
        }

        $user = UserAccount::query()
            ->where($loginType, $account)
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $message = 'Invalid account or password.';

            $this->writeLoginLog(null, $account, $loginType, $ip, 'failed', $message);

            throw new InvalidArgumentException($message);
        }

        if (! UserAccountStatus::canLogin($user->status)) {
            $message = 'User account is not active.';

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

        session(['user' => $publicUser]);

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

        $message = strtolower($exception->getMessage() . ' ' . implode(' ', $exception->errorInfo ?? []));

        if ($mobile !== null && $this->mentionsUniqueColumn($message, 'mobile')) {
            return new InvalidArgumentException('Mobile already exists.', 0, $exception);
        }

        if ($email !== null && $this->mentionsUniqueColumn($message, 'email')) {
            return new InvalidArgumentException('Email already exists.', 0, $exception);
        }

        if ($mobile !== null && UserAccount::query()->where('mobile', $mobile)->exists()) {
            return new InvalidArgumentException('Mobile already exists.', 0, $exception);
        }

        if ($email !== null && UserAccount::query()->where('email', $email)->exists()) {
            return new InvalidArgumentException('Email already exists.', 0, $exception);
        }

        if ($mobile !== null && $email === null) {
            return new InvalidArgumentException('Mobile already exists.', 0, $exception);
        }

        if ($email !== null && $mobile === null) {
            return new InvalidArgumentException('Email already exists.', 0, $exception);
        }

        return null;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage() . ' ' . implode(' ', $exception->errorInfo ?? []));

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
        ];
    }
}
