<?php

namespace Tests\Feature\User;

use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserAccount;
use App\Models\UserVipRecord;
use App\Models\VipPlan;
use App\User\UserAuthService;
use App\User\VipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserVipActivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 10, 0, 0));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_vip_activation_phase_4_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('vip_plan'));
        $this->assertTrue(Schema::hasTable('user_vip_record'));
        $this->assertTrue(Schema::hasTable('activation_code_batch'));
        $this->assertTrue(Schema::hasTable('activation_code'));
        $this->assertTrue(Schema::hasTable('activation_code_redemption'));

        $this->assertTrue(Schema::hasColumns('vip_plan', [
            'name',
            'level',
            'duration_days',
            'price',
            'status',
            'is_commissionable',
            'first_level_rate',
            'second_level_rate',
            'benefits_json',
            'create_time',
            'update_time',
        ]));
        $this->assertTrue(Schema::hasColumns('user_vip_record', [
            'user_id',
            'source_type',
            'source_id',
            'vip_plan_id',
            'before_expires_at',
            'after_expires_at',
            'duration_days',
            'status',
            'create_time',
        ]));
        $this->assertTrue(Schema::hasColumns('activation_code_batch', [
            'name',
            'vip_plan_id',
            'duration_days',
            'total_count',
            'generated_count',
            'status',
            'is_commissionable',
            'first_level_reward',
            'second_level_reward',
            'expires_at',
            'create_admin_id',
            'create_time',
            'update_time',
        ]));
        $this->assertTrue(Schema::hasColumns('activation_code', [
            'batch_id',
            'code_hash',
            'display_code_tail',
            'status',
            'max_uses',
            'used_count',
            'bound_user_id',
            'expires_at',
            'create_time',
            'update_time',
        ]));
        $this->assertTrue(Schema::hasColumns('activation_code_redemption', [
            'activation_code_id',
            'batch_id',
            'user_id',
            'vip_record_id',
            'commission_source_id',
            'redeem_ip',
            'result',
            'error_message',
            'create_time',
        ]));

        $this->assertSame(0, VipPlan::query()->count());
        $this->assertSame(0, UserVipRecord::query()->count());
        $this->assertSame(0, ActivationCodeBatch::query()->count());
        $this->assertSame(0, ActivationCode::query()->count());
        $this->assertSame(0, ActivationCodeRedemption::query()->count());
    }

    public function test_vip_service_grants_vip_to_non_vip_user(): void
    {
        $user = $this->registerUser('vip-grant@example.com');
        $plan = $this->createVipPlan('Monthly VIP', 1, 30);

        $result = app(VipService::class)->grant($user['user']['id'], $plan->id, 'activation_code', 101);

        $this->assertSame($user['user']['id'], $result['user_id']);
        $this->assertSame(1, $result['vip_level']);
        $this->assertSame('2026-08-04 10:00:00', Carbon::parse($result['vip_expires_at'])->format('Y-m-d H:i:s'));

        $account = UserAccount::query()->findOrFail($user['user']['id']);
        $this->assertSame(1, (int) $account->vip_level);
        $this->assertSame('2026-08-04 10:00:00', Carbon::parse($account->vip_expires_at)->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('user_vip_record', [
            'user_id' => $user['user']['id'],
            'source_type' => 'activation_code',
            'source_id' => 101,
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'status' => 'active',
        ]);
    }

    public function test_vip_service_extends_active_vip_from_current_expiry_and_upgrades_level(): void
    {
        $user = $this->registerUser('vip-extend@example.com');
        $monthly = $this->createVipPlan('Monthly VIP', 1, 30);
        $premium = $this->createVipPlan('Premium VIP', 3, 30);

        app(VipService::class)->grant($user['user']['id'], $monthly->id, 'activation_code', 201);
        $result = app(VipService::class)->grant($user['user']['id'], $premium->id, 'activation_code', 202);

        $this->assertSame(3, $result['vip_level']);
        $this->assertSame('2026-09-03 10:00:00', Carbon::parse($result['vip_expires_at'])->format('Y-m-d H:i:s'));

        $records = UserVipRecord::query()->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame('2026-08-04 10:00:00', Carbon::parse($records[1]->before_expires_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 10:00:00', Carbon::parse($records[1]->after_expires_at)->format('Y-m-d H:i:s'));

        $account = UserAccount::query()->findOrFail($user['user']['id']);
        $this->assertSame(3, (int) $account->vip_level);
    }

    public function test_vip_service_summary_returns_current_status(): void
    {
        $user = $this->registerUser('vip-summary@example.com');
        $plan = $this->createVipPlan('Monthly VIP', 2, 30);

        $empty = app(VipService::class)->summary($user['user']['id']);
        $this->assertFalse($empty['active']);
        $this->assertSame(0, $empty['vip_level']);
        $this->assertSame(0, $empty['record_count']);

        app(VipService::class)->grant($user['user']['id'], $plan->id, 'activation_code', 301);

        $summary = app(VipService::class)->summary($user['user']['id']);
        $this->assertTrue($summary['active']);
        $this->assertSame(2, $summary['vip_level']);
        $this->assertSame('2026-08-04 10:00:00', Carbon::parse($summary['vip_expires_at'])->format('Y-m-d H:i:s'));
        $this->assertSame(1, $summary['record_count']);
    }

    private function registerUser(string $email): array
    {
        return app(UserAuthService::class)->register([
            'email' => $email,
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    private function createVipPlan(string $name, int $level, int $durationDays): VipPlan
    {
        return VipPlan::query()->create([
            'name' => $name,
            'level' => $level,
            'duration_days' => $durationDays,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
