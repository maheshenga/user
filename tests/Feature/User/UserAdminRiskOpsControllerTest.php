<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;
use App\Models\VipPlan;
use App\User\BalanceLedgerService;
use App\User\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminRiskOpsControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_admin_commission_batch_review_and_stats(): void
    {
        [$buyer, $beneficiary] = $this->createBuyerAndBeneficiary('ops-commission');
        $first = $this->createCommission($buyer, $beneficiary, 1, '8.00', 'pending', 2001);
        $second = $this->createCommission($buyer, $beneficiary, 2, '3.00', 'pending', 2002);
        $third = $this->createCommission($buyer, $beneficiary, 1, '2.00', 'pending', 2003);

        $approve = $this->postJson('/admin/user/commission/batchApprove', [
            'ids' => [$first->id, $second->id],
        ]);
        $approve->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonCount(2, 'data.processed')
            ->assertJsonPath('data.errors', []);

        $rejectBlank = $this->postJson('/admin/user/commission/batchReject', [
            'ids' => [$third->id],
            'reason' => '',
        ]);
        $rejectBlank->assertOk()
            ->assertJsonPath('code', 0);

        $reject = $this->postJson('/admin/user/commission/batchReject', [
            'ids' => [$third->id],
            'reason' => 'Risk batch reject',
        ]);
        $reject->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonCount(1, 'data.processed');

        $stats = $this->getJson('/admin/user/commission/stats');
        $stats->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.by_status.settled.count', 2)
            ->assertJsonPath('data.by_status.settled.amount', '11.00')
            ->assertJsonPath('data.by_status.rejected.count', 1)
            ->assertJsonPath('data.top_beneficiaries.0.beneficiary_user_id', $beneficiary->id);
    }

    public function test_admin_activation_code_export_returns_safe_metadata_only(): void
    {
        $this->generateActivationCode();

        $export = $this->getJson('/admin/user/activation-code/export');
        $export->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonCount(1, 'data.rows');

        $row = $export->json('data.rows.0');
        $this->assertSame([
            'id',
            'batch_id',
            'display_code_tail',
            'status',
            'max_uses',
            'used_count',
            'bound_user_id',
            'expires_at',
            'create_time',
        ], array_keys($row));
        $this->assertArrayNotHasKey('code_hash', $row);
        $this->assertArrayNotHasKey('code', $row);
    }

    public function test_admin_risk_event_index_and_review(): void
    {
        $user = $this->createAccount('risk-admin@example.com');
        $event = UserRiskEvent::query()->create([
            'user_id' => $user->id,
            'category' => 'invite',
            'event_type' => 'invite_burst',
            'severity' => 'medium',
            'source_type' => 'user_invite_relation',
            'source_id' => 10,
            'ip' => '127.0.0.1',
            'status' => 'open',
            'detail_json' => ['count' => 5],
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $index = $this->getJson('/admin/user/risk-event/index');
        $index->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $event->id);
        $this->assertArrayNotHasKey('detail_json', $index->json('data.0'));

        $review = $this->postJson('/admin/user/risk-event/review', [
            'id' => $event->id,
            'status' => 'ignored',
        ]);
        $review->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.review_admin_id', 77);
    }

    public function test_admin_withdrawal_index_approve_and_reject(): void
    {
        $approveUser = $this->createAccount('withdraw-admin-approve@example.com', '30.00');
        $rejectUser = $this->createAccount('withdraw-admin-reject@example.com', '30.00');
        $service = app(WithdrawalService::class);
        $approve = $service->request($approveUser->id, '10.00', ['account_no' => 'approve'], '127.0.0.1');
        $reject = $service->request($rejectUser->id, '8.00', ['account_no' => 'reject'], '127.0.0.1');

        $index = $this->getJson('/admin/user/withdrawal/index');
        $index->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 2);

        $paid = $this->postJson('/admin/user/withdrawal/approve', ['id' => $approve['id']]);
        $paid->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.audit_admin_id', 77);

        $rejected = $this->postJson('/admin/user/withdrawal/reject', [
            'id' => $reject['id'],
            'reason' => 'Risk rejected',
        ]);
        $rejected->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Risk rejected');

        $this->assertDatabaseHas('user_account', [
            'id' => $approveUser->id,
            'available_balance' => '20.00',
            'frozen_balance' => '0.00',
        ]);
        $this->assertDatabaseHas('user_account', [
            'id' => $rejectUser->id,
            'available_balance' => '30.00',
            'frozen_balance' => '0.00',
        ]);
    }

    public function test_admin_risk_ops_controllers_block_unsafe_inherited_actions(): void
    {
        $user = $this->createAccount('risk-block@example.com', '10.00');
        $event = UserRiskEvent::query()->create([
            'user_id' => $user->id,
            'category' => 'activation_code',
            'event_type' => 'activation_code_failed',
            'severity' => 'low',
            'ip' => '127.0.0.1',
            'status' => 'open',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $withdrawal = app(WithdrawalService::class)->request($user->id, '5.00', ['account_no' => 'masked'], '127.0.0.1');

        foreach ([
            ['postJson', '/admin/user/risk-event/add', ['id' => $event->id]],
            ['postJson', '/admin/user/risk-event/edit', ['id' => $event->id, 'status' => 'ignored']],
            ['postJson', '/admin/user/risk-event/delete', ['id' => $event->id]],
            ['postJson', '/admin/user/risk-event/modify', ['id' => $event->id, 'field' => 'status', 'value' => 'ignored']],
            ['getJson', '/admin/user/risk-event/recycle', []],
            ['postJson', '/admin/user/withdrawal/add', ['id' => $withdrawal['id']]],
            ['postJson', '/admin/user/withdrawal/edit', ['id' => $withdrawal['id'], 'status' => 'paid']],
            ['postJson', '/admin/user/withdrawal/delete', ['id' => $withdrawal['id']]],
            ['postJson', '/admin/user/withdrawal/modify', ['id' => $withdrawal['id'], 'field' => 'status', 'value' => 'paid']],
            ['getJson', '/admin/user/withdrawal/recycle', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertOk()
                ->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/risk-event/export')->assertForbidden();
        $this->getJson('/admin/user/withdrawal/export')->assertForbidden();

        $this->assertDatabaseHas('user_risk_event', ['id' => $event->id, 'status' => 'open']);
        $this->assertDatabaseHas('user_withdrawal_request', ['id' => $withdrawal['id'], 'status' => 'pending']);
    }

    private function createBuyerAndBeneficiary(string $prefix): array
    {
        return [
            $this->createAccount("{$prefix}-buyer@example.com"),
            $this->createAccount("{$prefix}-beneficiary@example.com"),
        ];
    }

    private function createAccount(string $email, string $availableBalance = '0.00'): UserAccount
    {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'nickname' => $email,
            'status' => 'active',
            'available_balance' => $availableBalance,
            'frozen_balance' => '0.00',
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createCommission(
        UserAccount $buyer,
        UserAccount $beneficiary,
        int $level,
        string $amount,
        string $status,
        int $sourceId
    ): AffiliateCommission {
        return AffiliateCommission::query()->create([
            'source_type' => 'activation_code',
            'source_id' => $sourceId,
            'buyer_user_id' => $buyer->id,
            'beneficiary_user_id' => $beneficiary->id,
            'level' => $level,
            'amount' => $amount,
            'status' => $status,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function generateActivationCode(): void
    {
        $plan = VipPlan::query()->create([
            'name' => 'Export VIP',
            'level' => 1,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $batch = ActivationCodeBatch::query()->create([
            'name' => 'Export Batch',
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'total_count' => 1,
            'generated_count' => 0,
            'status' => 'active',
            'create_admin_id' => 77,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        ActivationCode::query()->create([
            'batch_id' => $batch->id,
            'code_hash' => hash('sha256', 'EA8EXPORTSAFE'),
            'display_code_tail' => 'RTSAFE',
            'status' => 'unused',
            'max_uses' => 1,
            'used_count' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $this->assertSame(1, ActivationCode::query()->count());
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 80)->default('');
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
