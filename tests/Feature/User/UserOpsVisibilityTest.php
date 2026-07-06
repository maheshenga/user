<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;
use App\User\UserOpsDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserOpsVisibilityTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('v', 32)));

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();

        $this->withoutMiddleware([
            CheckInstall::class,
            CheckAuth::class,
            RateLimiting::class,
            SystemLog::class,
        ]);

        DB::table('system_admin')->updateOrInsert(
            ['id' => 77],
            ['status' => 1, 'auth_ids' => '']
        );

        $this->withSession([
            'admin.id' => 77,
            'admin.expire_time' => true,
        ]);
    }

    public function test_user_ops_dashboard_metrics_return_zero_values_for_empty_tables(): void
    {
        $metrics = app(UserOpsDashboardService::class)->metrics();

        $this->assertSame(0, $metrics['total_users']);
        $this->assertSame(0, $metrics['today_registrations']);
        $this->assertSame(0, $metrics['active_vip_users']);
        $this->assertSame(0, $metrics['pending_withdrawals']);
        $this->assertSame(0, $metrics['pending_payouts']);
        $this->assertSame(0, $metrics['pending_notifications']);
        $this->assertSame(0, $metrics['retryable_notifications']);
        $this->assertSame(0, $metrics['risk_events']);
        $this->assertSame('0.00', $metrics['today_commission_amount']);
    }

    public function test_user_ops_dashboard_metrics_reflect_current_operations_data(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 5, 12, 0, 0));

        try {
            UserAccount::query()->create([
                'email' => 'normal@example.com',
                'password' => 'secret123',
                'create_time' => now()->timestamp,
            ]);
            UserAccount::query()->create([
                'email' => 'vip@example.com',
                'password' => 'secret123',
                'vip_level' => 2,
                'vip_expires_at' => now()->addDay(),
                'create_time' => now()->subDay()->timestamp,
            ]);

            UserWithdrawalRequest::query()->create([
                'withdrawal_no' => 'WD202607050001',
                'user_id' => 1,
                'amount' => '10.00',
                'status' => 'pending',
                'request_ip' => '127.0.0.1',
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);
            UserWithdrawalRequest::query()->create([
                'withdrawal_no' => 'WD202607050002',
                'user_id' => 2,
                'amount' => '20.00',
                'status' => 'approved',
                'request_ip' => '127.0.0.1',
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);
            UserWithdrawalRequest::query()->create([
                'withdrawal_no' => 'WD202607050003',
                'user_id' => 2,
                'amount' => '30.00',
                'status' => 'payout_failed',
                'request_ip' => '127.0.0.1',
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);

            UserNotificationOutbox::query()->create([
                'user_id' => 1,
                'type' => 'password_reset',
                'channel' => 'email',
                'recipient' => 'normal@example.com',
                'recipient_mask' => 'n***@example.com',
                'subject' => 'Reset',
                'payload_json' => ['token' => 'secret'],
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => now()->subMinute(),
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);
            UserNotificationOutbox::query()->create([
                'user_id' => 2,
                'type' => 'password_reset',
                'channel' => 'email',
                'recipient' => 'vip@example.com',
                'recipient_mask' => 'v***@example.com',
                'subject' => 'Reset',
                'payload_json' => ['token' => 'secret'],
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => now()->addHour(),
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);

            UserRiskEvent::query()->create([
                'user_id' => 1,
                'category' => 'auth',
                'event_type' => 'login_failed',
                'severity' => 'medium',
                'ip' => '127.0.0.1',
                'status' => 'open',
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);

            AffiliateCommission::query()->create([
                'source_type' => 'vip_order',
                'source_id' => 1001,
                'buyer_user_id' => 1,
                'beneficiary_user_id' => 2,
                'level' => 1,
                'amount' => '12.34',
                'status' => 'pending',
                'create_time' => now()->timestamp,
                'update_time' => now()->timestamp,
            ]);

            $metrics = app(UserOpsDashboardService::class)->metrics();

            $this->assertSame(2, $metrics['total_users']);
            $this->assertSame(1, $metrics['today_registrations']);
            $this->assertSame(1, $metrics['active_vip_users']);
            $this->assertSame(1, $metrics['pending_withdrawals']);
            $this->assertSame(2, $metrics['pending_payouts']);
            $this->assertSame(2, $metrics['pending_notifications']);
            $this->assertSame(1, $metrics['retryable_notifications']);
            $this->assertSame(1, $metrics['risk_events']);
            $this->assertSame('12.34', $metrics['today_commission_amount']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_user_ops_dashboard_json_returns_metrics(): void
    {
        $response = $this->getJson('/admin/user/dashboard/index');

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.total_users', 0)
            ->assertJsonPath('data.today_commission_amount', '0.00');
    }

    public function test_admin_user_ops_dashboard_page_renders(): void
    {
        $response = $this->get('/admin/user/dashboard/index');

        $response->assertOk();
        $response->assertSee('User Operations');
        $response->assertSee('Total Users');
    }

    public function test_user_ops_menu_sync_creates_visible_menu_entries(): void
    {
        $this->artisan('user:ops-menu:sync')
            ->expectsOutputToContain('synced=14')
            ->assertExitCode(0);

        $parent = DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', 'User Operations')
            ->where('href', '')
            ->whereNull('delete_time')
            ->first();

        $this->assertNotNull($parent);
        $this->assertSame(1, (int) $parent->status);

        foreach ($this->expectedMenuEntries() as $href => $title) {
            $this->assertDatabaseHas('system_menu', [
                'pid' => $parent->id,
                'title' => $title,
                'href' => $href,
                'status' => 1,
                'delete_time' => null,
            ]);
        }
    }

    public function test_user_ops_menu_sync_is_idempotent(): void
    {
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('system_menu')
                ->where('pid', 0)
                ->where('title', 'User Operations')
                ->where('href', '')
                ->whereNull('delete_time')
                ->count()
        );

        foreach ($this->expectedMenuEntries() as $href => $title) {
            $this->assertSame(
                1,
                DB::table('system_menu')
                    ->where('href', $href)
                    ->where('title', $title)
                    ->whereNull('delete_time')
                    ->count(),
                "Expected exactly one menu row for [{$href}]."
            );
        }
    }

    private function expectedMenuEntries(): array
    {
        return [
            'user/dashboard/index' => 'Overview',
            'user/account/index' => 'User Accounts',
            'user/invite/index' => 'Invite Codes',
            'user/invite/relations' => 'Invite Relations',
            'user/vip-plan/index' => 'VIP Plans',
            'user/activation-code/index' => 'Activation Codes',
            'user/activation-code/redemptions' => 'Activation Redemptions',
            'user/balance/index' => 'Balance Ledger',
            'user/commission/index' => 'Affiliate Commissions',
            'user/withdrawal/index' => 'Withdrawal Review',
            'user/risk-event/index' => 'Risk Events',
            'user/security-log/index' => 'Security Logs',
            'user/notification-outbox/index' => 'Notification Outbox',
            'user/settings/index' => 'Settings',
        ];
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 120)->default('');
                $table->string('name', 120);
                $table->text('value')->nullable();
            });
        }

        DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => '8.0.0'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin8'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'textarea'],
            ['group' => 'site', 'name' => 'iframe_open_top', 'value' => '0'],
        ]);
    }
}
