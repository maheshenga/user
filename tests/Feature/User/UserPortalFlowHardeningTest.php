<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\User\UserAccountStatus;
use App\User\UserAuthService;
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
            ->assertJsonPath('msg', '请先登录。')
            ->assertJsonPath('data', []);
    }

    public function test_session_endpoint_returns_current_session_user_without_password(): void
    {
        $registered = app(UserAuthService::class)->register([
            'email' => 'session@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $sessionUser = $registered['user'];
        $sessionUser['password'] = 'must-not-leak';

        $this->withSession(['user' => $sessionUser])
            ->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('msg', '用户会话')
            ->assertJsonPath('data.user.id', $registered['user']['id'])
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
            ->assertJsonPath('msg', '请先登录。');
    }

    public function test_dashboard_summary_requires_user_login(): void
    {
        $this->getJson('/user/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请先登录。');
    }

    public function test_dashboard_summary_returns_all_first_load_panels(): void
    {
        $registered = app(UserAuthService::class)->register([
            'email' => 'summary@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->withSession(['user' => $registered['user']])
            ->getJson('/user/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('msg', '仪表盘概览')
            ->assertJsonPath('data.user.email', 'summary@example.com')
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'vip',
                    'balance',
                    'ledger',
                    'withdrawals',
                    'invite',
                    'inviteRecords',
                ],
            ]);
    }

    public function test_user_session_endpoint_rejects_disabled_stale_session_user(): void
    {
        $registered = app(UserAuthService::class)->register([
            'email' => 'disabled-session@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        UserAccount::query()
            ->whereKey($registered['user']['id'])
            ->update(['status' => UserAccountStatus::DISABLED]);

        $this->withSession(['user' => $registered['user']])
            ->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请先登录。')
            ->assertSessionMissing('user');
    }

    public function test_user_protected_endpoints_reject_deleted_stale_session_user(): void
    {
        $registered = app(UserAuthService::class)->register([
            'email' => 'deleted-session@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        UserAccount::query()->whereKey($registered['user']['id'])->delete();

        foreach ([
            '/user/dashboard/summary',
            '/user/vip',
            '/user/balance',
            '/user/balance/ledger',
            '/user/withdrawal',
            '/user/invite',
            '/user/invite/records',
        ] as $endpoint) {
            $this->withSession(['user' => $registered['user']])
                ->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('msg', '请先登录。')
                ->assertSessionMissing('user');
        }

        foreach ([
            ['/user/activation-code/redeem', ['code' => 'ANY-CODE']],
            ['/user/withdrawal/request', ['amount' => '1.00', 'account' => ['account_no' => 'ACCT-1']]],
        ] as [$endpoint, $payload]) {
            $this->withSession(['user' => $registered['user']])
                ->postJson($endpoint, $payload)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('msg', '请先登录。')
                ->assertSessionMissing('user');
        }
    }

    public function test_portal_forms_disable_submit_controls_while_request_is_pending(): void
    {
        $script = file_get_contents(public_path('static/user/js/portal.js'));

        $this->assertStringContainsString('setFormBusy(form, true)', $script);
        $this->assertStringContainsString('setFormBusy(form, false)', $script);
        $this->assertStringContainsString('setFormBusy(activationForm, true)', $script);
        $this->assertStringContainsString('setFormBusy(withdrawalForm, true)', $script);
    }
}
