<?php

namespace Tests\Feature\User;

use Symfony\Component\Process\Process;
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
        $script = <<<'JS'
const fs = require('fs');
const assert = require('assert');

global.window = {};
global.document = {
    querySelector() { return null; },
    querySelectorAll() { return []; },
};

eval(fs.readFileSync('public/static/user/js/portal.js', 'utf8'));

assert(window.UserPortalDashboardRenderers, 'dashboard renderers test API missing');

function assertRow(html, label, value) {
    assert(
        html.includes(`<span>${label}</span><strong>${value}</strong>`),
        `Missing summary row: ${label}=${value}`
    );
}

const vip = window.UserPortalDashboardRenderers.render('vip', {
    active: true,
    vip_level: 2,
    vip_expires_at: '2026-08-01 00:00:00',
    record_count: 1,
});
assertRow(vip, 'VIP Level', '2');
assertRow(vip, 'Status', 'active');
assertRow(vip, 'Expired At', '2026-08-01 00:00:00');
assertRow(vip, 'Active Records', '1');

const ledger = window.UserPortalDashboardRenderers.render('ledger', [{
    amount: '12.34',
    type: 'commission',
    remark: '<bonus>',
    create_time: '2026-07-06 09:00:00',
}]);
assertRow(ledger, 'Amount', '12.34');
assertRow(ledger, 'Type', 'commission');
assertRow(ledger, 'Reason', '&lt;bonus&gt;');
assertRow(ledger, 'Time', '2026-07-06 09:00:00');
assert(!ledger.includes('<bonus>'));
assert(!ledger.includes('No balance ledger records.'));

const invite = window.UserPortalDashboardRenderers.render('invite', {
    invite_code: { code: 'ABC123' },
    direct_count: 3,
    second_level_count: 2,
});
assertRow(invite, 'Invite Code', 'ABC123');
assertRow(invite, 'Level 1 Total', '3');
assertRow(invite, 'Level 2 Total', '2');

const inviteRecords = window.UserPortalDashboardRenderers.render('inviteRecords', [{
    email: 'friend@example.com',
    status: 'active',
    level_path: '1/2',
    create_time: '2026-07-06 09:01:00',
}]);
assertRow(inviteRecords, 'User', 'friend@example.com');
assertRow(inviteRecords, 'Status', 'active');
assertRow(inviteRecords, 'Path', '1/2');
assertRow(inviteRecords, 'Registered At', '2026-07-06 09:01:00');
assert(!inviteRecords.includes('No invite records.'));

const withdrawals = window.UserPortalDashboardRenderers.render('withdrawals', [{
    withdrawal_no: 'WD202607060001',
    amount: '10.00',
    status: 'paid',
    account_snapshot_json: { account_no: 'ACCT-1' },
    payout_transaction_id: 'TX-1',
    paid_at: '2026-07-06 09:02:00',
}]);
assertRow(withdrawals, 'No.', 'WD202607060001');
assertRow(withdrawals, 'Account', 'ACCT-1');
assertRow(withdrawals, 'Payout Transaction', 'TX-1');
assertRow(withdrawals, 'Paid At', '2026-07-06 09:02:00');
assert(!withdrawals.includes('Requested At'));
JS;

        $process = new Process(['node', '-e', $script], base_path());
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
