<?php

namespace Tests\Feature\User;

use Tests\TestCase;

class UserPortalFlowHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_session_endpoint_requires_user_login(): void
    {
        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', 'User login required.')
            ->assertJsonPath('data', []);
    }

    public function test_session_endpoint_returns_current_session_user_without_password(): void
    {
        $this->withSession([
            'user' => [
                'id' => 99,
                'email' => 'session@example.com',
                'mobile' => null,
                'nickname' => 'Session User',
                'password' => 'must-not-leak',
            ],
        ])->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('msg', 'User session')
            ->assertJsonPath('data.user.id', 99)
            ->assertJsonPath('data.user.email', 'session@example.com')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_register_login_session_vip_logout_flow_uses_existing_user_apis(): void
    {
        $this->postJson('/user/register', [
            'email' => 'flow@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com');

        $this->postJson('/user/login', [
            'account' => 'flow@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com')
            ->assertSessionHas('user.email', 'flow@example.com');

        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com');

        $this->getJson('/user/vip')
            ->assertOk()
            ->assertJsonPath('code', 1);

        $this->postJson('/user/logout')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertSessionMissing('user');

        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', 'User login required.');
    }
}
