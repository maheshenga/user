<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserOpsSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createSystemConfigTable();
        Cache::flush();
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
