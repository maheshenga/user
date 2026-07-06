<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserAccount;
use App\Models\UserVipRecord;
use App\Models\VipPlan;
use App\User\ActivationCodeService;
use App\User\UserAuthService;
use App\User\VipService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
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

    public function test_activation_service_creates_batch_and_generates_hashed_codes(): void
    {
        $plan = $this->createVipPlan('Activation VIP', 2, 60);

        $batch = app(ActivationCodeService::class)->createBatch([
            'name' => 'July Campaign',
            'vip_plan_id' => $plan->id,
            'duration_days' => 60,
            'total_count' => 3,
            'status' => 'active',
            'is_commissionable' => true,
            'first_level_reward' => 12.50,
            'second_level_reward' => 3.25,
            'expires_at' => '2026-08-01 00:00:00',
        ], 9);

        $this->assertSame('July Campaign', $batch['name']);
        $this->assertSame(3, $batch['total_count']);
        $this->assertSame(0, $batch['generated_count']);
        $this->assertTrue($batch['is_commissionable']);

        $generated = app(ActivationCodeService::class)->generateCodes($batch['id'], 2, 9);

        $this->assertCount(2, $generated['codes']);
        foreach ($generated['codes'] as $plainCode) {
            $this->assertMatchesRegularExpression('/^EA8-[A-Z0-9]{4}(?:-[A-Z0-9]{4}){5}$/', $plainCode);
        }

        $storedCodes = ActivationCode::query()->orderBy('id')->get();
        $this->assertCount(2, $storedCodes);
        foreach ($storedCodes as $index => $storedCode) {
            $normalized = $this->normalizeActivationCode($generated['codes'][$index]);

            $this->assertSame(hash('sha256', $normalized), $storedCode->code_hash);
            $this->assertSame(substr($normalized, -6), $storedCode->display_code_tail);
            $this->assertNotSame($generated['codes'][$index], $storedCode->code_hash);
        }

        $this->assertSame(2, (int) ActivationCodeBatch::query()->findOrFail($batch['id'])->generated_count);
    }

    public function test_activation_service_redeems_valid_code_and_writes_success_audit(): void
    {
        $user = $this->registerUser('activation-redeem@example.com');
        $plan = $this->createVipPlan('Activation VIP', 2, 30);
        $code = $this->generateOneActivationCode($plan);

        $result = app(ActivationCodeService::class)->redeem([
            'code' => strtolower(' '.$code.' '),
        ], $user['user']['id'], '127.0.0.5');

        $this->assertTrue($result['redeemed']);
        $this->assertSame(2, $result['vip']['vip_level']);

        $storedCode = ActivationCode::query()->firstOrFail();
        $this->assertSame('used', $storedCode->status);
        $this->assertSame(1, (int) $storedCode->used_count);

        $this->assertDatabaseHas('activation_code_redemption', [
            'activation_code_id' => $storedCode->id,
            'batch_id' => $storedCode->batch_id,
            'user_id' => $user['user']['id'],
            'result' => 'success',
            'redeem_ip' => '127.0.0.5',
        ]);
        $this->assertSame(1, UserVipRecord::query()->where('user_id', $user['user']['id'])->count());
    }

    public function test_activation_service_rejects_reused_single_use_code_and_writes_failed_audit(): void
    {
        $firstUser = $this->registerUser('activation-first@example.com');
        $secondUser = $this->registerUser('activation-second@example.com');
        $plan = $this->createVipPlan('Activation VIP', 1, 30);
        $code = $this->generateOneActivationCode($plan);

        app(ActivationCodeService::class)->redeem([
            'code' => $code,
        ], $firstUser['user']['id'], '127.0.0.6');

        try {
            app(ActivationCodeService::class)->redeem([
                'code' => $code,
            ], $secondUser['user']['id'], '127.0.0.7');

            $this->fail('Expected reused activation code to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('激活码当前不可用。', $exception->getMessage());
        }

        $this->assertSame(1, UserVipRecord::query()->where('user_id', $firstUser['user']['id'])->count());
        $this->assertSame(0, UserVipRecord::query()->where('user_id', $secondUser['user']['id'])->count());
        $this->assertSame(2, ActivationCodeRedemption::query()->count());
        $this->assertDatabaseHas('activation_code_redemption', [
            'user_id' => $secondUser['user']['id'],
            'result' => 'failed',
            'error_message' => '激活码当前不可用。',
        ]);
    }

    public function test_activation_service_rejects_unusable_codes_without_leaking_secrets(): void
    {
        $user = $this->registerUser('activation-edge@example.com');
        $otherUser = $this->registerUser('activation-bound-other@example.com');
        $plan = $this->createVipPlan('Activation VIP', 1, 30);

        $cases = [
            ['status' => 'disabled', 'message' => '激活码当前不可用。'],
            ['status' => 'unused', 'expires_at' => Carbon::now()->subMinute(), 'message' => '激活码已过期。'],
            ['status' => 'unused', 'used_count' => 2, 'max_uses' => 2, 'message' => '激活码使用次数已达上限。'],
            ['status' => 'unused', 'bound_user_id' => $otherUser['user']['id'], 'message' => '激活码不属于当前用户。'],
        ];

        foreach ($cases as $index => $case) {
            $plainCode = "EA8-EDGE-CASE-{$index}";
            $batch = $this->createActivationBatch($plan);
            ActivationCode::query()->create([
                'batch_id' => $batch->id,
                'code_hash' => hash('sha256', $this->normalizeActivationCode($plainCode)),
                'display_code_tail' => substr($this->normalizeActivationCode($plainCode), -6),
                'status' => $case['status'],
                'max_uses' => $case['max_uses'] ?? 1,
                'used_count' => $case['used_count'] ?? 0,
                'bound_user_id' => $case['bound_user_id'] ?? null,
                'expires_at' => $case['expires_at'] ?? Carbon::now()->addDay(),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            try {
                app(ActivationCodeService::class)->redeem([
                    'code' => $plainCode,
                ], $user['user']['id'], '127.0.0.8');

                $this->fail("Expected activation code case {$index} to fail.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($case['message'], $exception->getMessage());
                $this->assertStringNotContainsString('EDGE', $exception->getMessage());
                $this->assertStringNotContainsString('hash', strtolower($exception->getMessage()));
            }
        }

        $this->assertSame(4, ActivationCodeRedemption::query()->where('result', 'failed')->count());
        $this->assertSame(0, UserVipRecord::query()->where('user_id', $user['user']['id'])->count());
    }

    public function test_user_vip_and_activation_endpoints_complete_flow(): void
    {
        $user = $this->registerUser('activation-endpoint@example.com');
        $plan = $this->createVipPlan('Endpoint VIP', 2, 30);
        $code = $this->generateOneActivationCode($plan);

        $this->withSession(['user' => $user['user']]);

        $summaryBefore = $this->getJson('/user/vip');
        $summaryBefore->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.vip_level', 0);

        $redeem = $this->postJson('/user/activation-code/redeem', [
            'code' => $code,
        ]);
        $redeem->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.redeemed', true)
            ->assertJsonPath('data.vip.vip_level', 2);

        $summaryAfter = $this->getJson('/user/vip');
        $summaryAfter->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.vip_level', 2);
    }

    public function test_user_vip_activation_endpoints_require_login_and_validate_payload(): void
    {
        $this->getJson('/user/vip')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->postJson('/user/activation-code/redeem', [
            'code' => 'EA8-ANY',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $user = $this->registerUser('activation-bad-payload@example.com');
        $this->withSession(['user' => $user['user']]);

        $this->postJson('/user/activation-code/redeem', [])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '激活码不能为空。');
    }

    public function test_user_vip_activation_routes_use_install_guard_and_throttle(): void
    {
        foreach ([
            ['GET', '/user/vip'],
            ['POST', '/user/activation-code/redeem'],
        ] as [$method, $path]) {
            $route = Route::getRoutes()->match(Request::create($path, $method));
            $middleware = $route->gatherMiddleware();

            $this->assertContains(CheckInstall::class, $middleware);
            $this->assertTrue(
                collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'throttle:')),
                "{$path} route must be rate limited.",
            );
        }
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

    private function createActivationBatch(VipPlan $plan): ActivationCodeBatch
    {
        return ActivationCodeBatch::query()->create([
            'name' => 'Test Batch',
            'vip_plan_id' => $plan->id,
            'duration_days' => (int) $plan->duration_days,
            'total_count' => 10,
            'generated_count' => 0,
            'status' => 'active',
            'expires_at' => Carbon::now()->addMonth(),
            'create_admin_id' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function generateOneActivationCode(VipPlan $plan): string
    {
        $batch = $this->createActivationBatch($plan);
        $generated = app(ActivationCodeService::class)->generateCodes($batch->id, 1, 1);

        return $generated['codes'][0];
    }

    private function normalizeActivationCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }
}
