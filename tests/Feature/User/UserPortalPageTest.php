<?php

namespace Tests\Feature\User;

use Tests\TestCase;

class UserPortalPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_user_portal_root_redirects_to_dashboard(): void
    {
        $this->get('/u')
            ->assertRedirect('/u/dashboard');
    }

    public function test_login_page_renders_existing_api_endpoint_hook(): void
    {
        $this->get('/u/login')
            ->assertOk()
            ->assertSee('data-portal-form', false)
            ->assertSee('data-endpoint="/user/login"', false)
            ->assertSee('name="account"', false)
            ->assertSee('name="password"', false);
    }

    public function test_register_page_renders_existing_api_endpoint_hook(): void
    {
        $this->get('/u/register')
            ->assertOk()
            ->assertSee('data-endpoint="/user/register"', false)
            ->assertSee('name="mobile"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="invite_code"', false);
    }

    public function test_password_pages_render_existing_api_endpoint_hooks(): void
    {
        $this->get('/u/forgot-password')
            ->assertOk()
            ->assertSee('data-endpoint="/user/password/forgot"', false)
            ->assertSee('name="account"', false);

        $this->get('/u/reset-password')
            ->assertOk()
            ->assertSee('data-endpoint="/user/password/reset"', false)
            ->assertSee('name="account"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="token"', false)
            ->assertSee('name="code"', false);
    }

    public function test_dashboard_renders_existing_user_api_endpoint_hooks(): void
    {
        $this->get('/u/dashboard')
            ->assertOk()
            ->assertSee('data-user-session', false)
            ->assertSee('data-dashboard-endpoints', false)
            ->assertSee('data-session="/user/session"', false)
            ->assertSee('data-vip="/user/vip"', false)
            ->assertSee('data-balance="/user/balance"', false)
            ->assertSee('data-ledger="/user/balance/ledger"', false)
            ->assertSee('data-withdrawals="/user/withdrawal"', false)
            ->assertSee('data-invite="/user/invite"', false)
            ->assertSee('data-invite-records="/user/invite/records"', false)
            ->assertSee('data-activation="/user/activation-code/redeem"', false)
            ->assertSee('data-withdrawal-request="/user/withdrawal/request"', false)
            ->assertSee('data-logout="/user/logout"', false)
            ->assertSee('data-dashboard-render="vip"', false)
            ->assertSee('data-dashboard-render="balance"', false)
            ->assertSee('data-dashboard-render="ledger"', false)
            ->assertSee('data-dashboard-render="invite"', false)
            ->assertSee('data-dashboard-render="inviteRecords"', false)
            ->assertSee('data-dashboard-render="withdrawals"', false);
    }

    public function test_dashboard_embeds_current_session_user_when_logged_in(): void
    {
        $this->withSession([
            'user' => [
                'id' => 42,
                'email' => 'portal@example.com',
                'mobile' => null,
                'nickname' => 'Portal User',
            ],
        ])->get('/u/dashboard')
            ->assertOk()
            ->assertSee('portal@example.com')
            ->assertSee('"id":42', false);
    }

    public function test_dashboard_renderer_supports_current_user_api_payload_shapes(): void
    {
        $script = file_get_contents(public_path('static/user/js/portal.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('Array.isArray(data)', $script);
        $this->assertStringContainsString('data.active', $script);
        $this->assertStringContainsString('data.direct_count', $script);
        $this->assertStringContainsString('data.second_level_count', $script);
        $this->assertStringContainsString('account_snapshot_json', $script);
        $this->assertStringContainsString('payout_transaction_id', $script);
        $this->assertStringContainsString('paid_at', $script);
    }
}
