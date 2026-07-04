<?php

namespace App\User;

final class UserAccountStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const FROZEN = 'frozen';

    public static function canLogin(string $status): bool
    {
        return $status === self::ACTIVE;
    }
}
