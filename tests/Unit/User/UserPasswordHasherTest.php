<?php

namespace Tests\Unit\User;

use App\User\UserPasswordHasher;
use Tests\TestCase;

class UserPasswordHasherTest extends TestCase
{
    public function test_hash_creates_non_plaintext_password_that_can_be_verified(): void
    {
        $hasher = new UserPasswordHasher();

        $hash = $hasher->hash('secret123');

        $this->assertNotSame('secret123', $hash);
        $this->assertTrue($hasher->verify('secret123', $hash));
        $this->assertFalse($hasher->verify('wrong-password', $hash));
    }
}
