<?php

namespace App\User;

use App\Models\UserAccount;
use BadMethodCallException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
        throw new BadMethodCallException('Login is implemented in Task 3.');
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
