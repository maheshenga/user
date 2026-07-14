<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserApiTokenAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_token_storage_schema_and_module_policy_are_available(): void
    {
        $this->assertTrue(class_exists(\Laravel\Sanctum\Sanctum::class));
        $this->assertTrue(method_exists(UserAccount::class, 'createToken'));

        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasTable('user_api_sessions'));
        $this->assertTrue(Schema::hasTable('user_api_refresh_tokens'));

        $this->assertTrue(Schema::hasColumns('user_api_sessions', [
            'user_id',
            'module',
            'device_id',
            'device_name',
            'access_token_id',
            'last_ip',
            'last_used_at',
            'revoked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('user_api_refresh_tokens', [
            'session_id',
            'token_hash',
            'expires_at',
            'used_at',
            'revoked_at',
        ]));

        $this->assertSame(15, config('user_api.access_token_minutes'));
        $this->assertSame(30, config('user_api.refresh_token_days'));
        $this->assertSame([
            'profile:read',
            'vip:read',
            'activation:redeem',
            'content:parse',
            'content:rewrite',
            'module:qingyu_ip_agent',
        ], config('user_api.modules.qingyu_ip_agent.abilities'));
    }
}
