<?php

namespace App\User;

use Illuminate\Support\Facades\Hash;

final class UserPasswordHasher
{
    public function hash(string $password): string
    {
        return Hash::make($password);
    }

    public function verify(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }
}
