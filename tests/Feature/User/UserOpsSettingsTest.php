<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserAccount;
use App\User\InviteService;
use App\User\PasswordResetService;
use App\User\RiskService;
use App\User\WithdrawalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserOpsSettingsTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();
        Cache::flush();

        $this->withoutMiddleware([
            CheckInstall::class,
            CheckAuth::class,
            RateLimiting::class,
            SystemLog::class,
        ]);

        $this->withSession([
            'admin.id' => 77,
            'admin.expire_time' => true,
        ]);
    }

    public function test_user_ops_settings_defaults_preserve_current_behavior(): void
    {
        $settings = app(\App\User\UserOpsSettings::class);

        $this->assertSame(0, $settings->inviteDefaultMaxUses());
        $this->assertSame(0, $settings->inviteDefaultExpiresDays());
        $this->assertSame(30, $settings->passwordResetExpiresMinutes());
        $this->assertSame(5, $settings->riskInviteBurstThreshold());
        $this->assertSame(24, $settings->riskInviteBurstWindowHours());
        $this->assertSame(5, $settings->riskActivationFailureThreshold());
        $this->assertSame(10, $settings->riskActivationFailureWindowMinutes());
        $this->assertSame('0.01', $settings->withdrawalMinAmount());
        $this->assertSame('0.00', $settings->withdrawalMaxAmount());
    }

    public function test_user_ops_settings_read_system_config_overrides(): void
    {
        DB::table('system_config')->insert([
            ['group' => 'user_ops', 'name' => 'invite_default_max_uses', 'value' => '3'],
            ['group' => 'user_ops', 'name' => 'invite_default_expires_days', 'value' => '14'],
            ['group' => 'user_ops', 'name' => 'password_reset_expires_minutes', 'value' => '45'],
            ['group' => 'user_ops', 'name' => 'risk_invite_burst_threshold', 'value' => '7'],
            ['group' => 'user_ops', 'name' => 'risk_invite_burst_window_hours', 'value' => '12'],
            ['group' => 'user_ops', 'name' => 'risk_activation_failure_threshold', 'value' => '4'],
            ['group' => 'user_ops', 'name' => 'risk_activation_failure_window_minutes', 'value' => '15'],
            ['group' => 'user_ops', 'name' => 'withdrawal_min_amount', 'value' => '10'],
            ['group' => 'user_ops', 'name' => 'withdrawal_max_amount', 'value' => '500.5'],
        ]);

        Cache::flush();

        $settings = app(\App\User\UserOpsSettings::class);

        $this->assertSame(3, $settings->inviteDefaultMaxUses());
        $this->assertSame(14, $settings->inviteDefaultExpiresDays());
        $this->assertSame(45, $settings->passwordResetExpiresMinutes());
        $this->assertSame(7, $settings->riskInviteBurstThreshold());
        $this->assertSame(12, $settings->riskInviteBurstWindowHours());
        $this->assertSame(4, $settings->riskActivationFailureThreshold());
        $this->assertSame(15, $settings->riskActivationFailureWindowMinutes());
        $this->assertSame('10.00', $settings->withdrawalMinAmount());
        $this->assertSame('500.50', $settings->withdrawalMaxAmount());
    }

    public function test_admin_user_ops_settings_page_renders_current_values(): void
    {
        $response = $this->get('/admin/user/settings/index');

        $response->assertOk();
        $response->assertSee('User Operations Settings');
        $response->assertSee('name="password_reset_expires_minutes"', false);
        $response->assertSee('value="30"', false);
    }

    public function test_admin_user_ops_settings_save_validates_and_persists_allowlisted_values(): void
    {
        $response = $this->postJson('/admin/user/settings/save', [
            'invite_default_max_uses' => '3',
            'invite_default_expires_days' => '14',
            'password_reset_expires_minutes' => '45',
            'risk_invite_burst_threshold' => '7',
            'risk_invite_burst_window_hours' => '12',
            'risk_activation_failure_threshold' => '4',
            'risk_activation_failure_window_minutes' => '15',
            'withdrawal_min_amount' => '10',
            'withdrawal_max_amount' => '500.5',
            'unexpected_key' => 'must-not-save',
        ]);

        $response->assertOk()->assertJsonPath('code', 1);

        $this->assertSame(
            '45',
            DB::table('system_config')
                ->where('group', 'user_ops')
                ->where('name', 'password_reset_expires_minutes')
                ->value('value')
        );
        $this->assertNull(
            DB::table('system_config')
                ->where('group', 'user_ops')
                ->where('name', 'unexpected_key')
                ->value('value')
        );
    }

    public function test_admin_user_ops_settings_save_rejects_invalid_amount_policy(): void
    {
        $response = $this->postJson('/admin/user/settings/save', [
            'withdrawal_min_amount' => '100',
            'withdrawal_max_amount' => '50',
        ]);

        $response->assertOk()->assertJsonPath('code', 0);
        $response->assertJsonFragment(['msg' => 'Withdrawal max amount must be zero or greater than min amount.']);
    }

    public function test_user_ops_menu_sync_adds_settings_entry(): void
    {
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);

        $this->assertDatabaseHas('system_menu', [
            'href' => 'user/settings/index',
            'title' => 'Settings',
        ]);
    }

    public function test_invite_default_code_uses_configured_limits(): void
    {
        $this->saveUserOpsConfig([
            'invite_default_max_uses' => '2',
            'invite_default_expires_days' => '10',
        ]);

        $user = $this->createUserAccount(['email' => 'invite-settings@example.com']);
        $code = app(InviteService::class)->createDefaultCode($user);

        $this->assertSame(2, (int) $code->max_uses);
        $this->assertNotNull($code->expires_at);
    }

    public function test_password_reset_uses_configured_expiry(): void
    {
        $this->saveUserOpsConfig(['password_reset_expires_minutes' => '45']);
        $this->createUserAccount(['email' => 'reset-settings@example.com']);

        $result = app(PasswordResetService::class)->requestReset([
            'account' => 'reset-settings@example.com',
        ], '127.0.0.1');

        $this->assertSame(2700, $result['expires_in']);
    }

    public function test_risk_service_uses_configured_activation_failure_threshold(): void
    {
        $this->saveUserOpsConfig([
            'risk_activation_failure_threshold' => '2',
            'risk_activation_failure_window_minutes' => '10',
        ]);

        $user = $this->createUserAccount(['email' => 'risk-settings@example.com']);

        app(RiskService::class)->recordActivationFailure((int) $user->id, '127.0.0.1', 'bad');
        $second = app(RiskService::class)->recordActivationFailure((int) $user->id, '127.0.0.1', 'bad');

        $this->assertSame('medium', $second['severity']);
    }

    public function test_withdrawal_amount_policy_uses_configured_min_and_max(): void
    {
        $this->saveUserOpsConfig([
            'withdrawal_min_amount' => '10.00',
            'withdrawal_max_amount' => '100.00',
        ]);

        $user = $this->createUserAccount([
            'email' => 'withdraw-settings@example.com',
            'available_balance' => '50.00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal amount must be at least 10.00.');

        app(WithdrawalService::class)->request((int) $user->id, '5.00', ['account' => 'bank'], '127.0.0.1');
    }

    public function test_withdrawal_amount_policy_rejects_configured_max(): void
    {
        $this->saveUserOpsConfig([
            'withdrawal_min_amount' => '10.00',
            'withdrawal_max_amount' => '100.00',
        ]);

        $user = $this->createUserAccount([
            'email' => 'withdraw-max-settings@example.com',
            'available_balance' => '150.00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal amount must be at most 100.00.');

        app(WithdrawalService::class)->request((int) $user->id, '120.00', ['account' => 'bank'], '127.0.0.1');
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

    private function saveUserOpsConfig(array $values): void
    {
        foreach ($values as $name => $value) {
            DB::table('system_config')->updateOrInsert([
                'group' => 'user_ops',
                'name' => $name,
            ], [
                'value' => (string) $value,
            ]);
        }

        Cache::flush();
    }

    private function createUserAccount(array $overrides = []): UserAccount
    {
        $now = time();

        return UserAccount::query()->create(array_merge([
            'mobile' => null,
            'email' => 'user-'.uniqid('', true).'@example.com',
            'nickname' => '',
            'password' => 'secret123',
            'status' => 'active',
            'vip_level' => 0,
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
            'register_ip' => '127.0.0.1',
            'create_time' => $now,
            'update_time' => $now,
        ], $overrides));
    }
}
