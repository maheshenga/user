<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Models\AffiliateCommission;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserAccount;
use App\Models\UserBalanceLedger;
use App\Models\UserInviteRelation;
use App\Models\VipPlan;
use App\User\ActivationCodeService;
use App\User\AffiliateService;
use App\User\BalanceLedgerService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAffiliateBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_affiliate_balance_phase_5_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('affiliate_commission'));
        $this->assertTrue(Schema::hasTable('user_balance_ledger'));

        $this->assertTrue(Schema::hasColumns('affiliate_commission', [
            'id',
            'source_type',
            'source_id',
            'buyer_user_id',
            'beneficiary_user_id',
            'level',
            'amount',
            'status',
            'reason',
            'audit_admin_id',
            'audited_at',
            'settled_ledger_id',
            'reversed_commission_id',
            'create_time',
            'update_time',
        ]));

        $this->assertTrue(Schema::hasColumns('user_balance_ledger', [
            'id',
            'user_id',
            'direction',
            'amount',
            'balance_before',
            'balance_after',
            'frozen_before',
            'frozen_after',
            'type',
            'source_type',
            'source_id',
            'remark',
            'admin_id',
            'create_time',
        ]));

        $commissionIndexes = collect(DB::select("PRAGMA index_list('affiliate_commission')"))
            ->pluck('name')
            ->all();

        $this->assertContains('affiliate_commission_source_level_beneficiary_unique', $commissionIndexes);
        $this->assertSame(0, AffiliateCommission::query()->count());
        $this->assertSame(0, UserBalanceLedger::query()->count());
    }

    public function test_balance_ledger_service_credits_and_debits_available_balance_with_snapshots(): void
    {
        $user = $this->createAccount('ledger-credit@example.com');
        $service = app(BalanceLedgerService::class);

        $credit = $service->credit(
            $user->id,
            '10.50',
            'affiliate_commission',
            'affiliate_commission',
            123,
            'Commission settlement',
            7
        );

        $this->assertSame('10.50', $credit['balance_after']);
        $this->assertSame('0.00', $credit['frozen_after']);
        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '10.50',
            'frozen_balance' => '0.00',
        ]);
        $this->assertDatabaseHas('user_balance_ledger', [
            'user_id' => $user->id,
            'direction' => 'in',
            'amount' => '10.50',
            'balance_before' => '0.00',
            'balance_after' => '10.50',
            'type' => 'affiliate_commission',
            'source_type' => 'affiliate_commission',
            'source_id' => 123,
            'admin_id' => 7,
        ]);

        $debit = $service->debit(
            $user->id,
            '4.25',
            'reversal',
            'affiliate_commission',
            123,
            'Commission reversal',
            7
        );

        $this->assertSame('6.25', $debit['balance_after']);
        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '6.25',
            'frozen_balance' => '0.00',
        ]);
        $this->assertSame(2, UserBalanceLedger::query()->where('user_id', $user->id)->count());
    }

    public function test_balance_ledger_service_rejects_insufficient_available_balance(): void
    {
        $user = $this->createAccount('ledger-insufficient@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Available balance is insufficient.');

        app(BalanceLedgerService::class)->debit($user->id, '0.01', 'reversal', null, null, 'Too much');
    }

    public function test_balance_ledger_service_freezes_and_unfreezes_balance(): void
    {
        $user = $this->createAccount('ledger-freeze@example.com', '20.00', '0.00');
        $service = app(BalanceLedgerService::class);

        $freeze = $service->freeze($user->id, '6.00', 'withdraw_freeze', null, null, 'Withdraw hold');

        $this->assertSame('14.00', $freeze['balance_after']);
        $this->assertSame('6.00', $freeze['frozen_after']);
        $this->assertDatabaseHas('user_balance_ledger', [
            'user_id' => $user->id,
            'direction' => 'freeze',
            'amount' => '6.00',
            'balance_before' => '20.00',
            'balance_after' => '14.00',
            'frozen_before' => '0.00',
            'frozen_after' => '6.00',
        ]);

        $unfreeze = $service->unfreeze($user->id, '2.50', 'withdraw_reject', null, null, 'Withdraw rejected');

        $this->assertSame('16.50', $unfreeze['balance_after']);
        $this->assertSame('3.50', $unfreeze['frozen_after']);
        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '16.50',
            'frozen_balance' => '3.50',
        ]);
    }

    public function test_balance_ledger_service_admin_adjust_requires_reason_and_admin_id(): void
    {
        $user = $this->createAccount('ledger-adjust@example.com', '10.00');
        $service = app(BalanceLedgerService::class);

        try {
            $service->adminAdjust($user->id, '5.00', ' ', 9);
            $this->fail('Expected blank admin adjustment reason to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Adjustment reason is required.', $exception->getMessage());
        }

        try {
            $service->adminAdjust($user->id, '5.00', 'Manual correction', 0);
            $this->fail('Expected missing admin id to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Admin id is required.', $exception->getMessage());
        }

        $ledger = $service->adminAdjust($user->id, '-3.25', 'Manual correction', 9);

        $this->assertSame('out', $ledger['direction']);
        $this->assertSame('-3.25', $ledger['signed_amount']);
        $this->assertDatabaseHas('user_balance_ledger', [
            'user_id' => $user->id,
            'direction' => 'out',
            'amount' => '3.25',
            'type' => 'admin_adjust',
            'remark' => 'Manual correction',
            'admin_id' => 9,
        ]);
    }

    public function test_balance_ledger_service_summary_and_ledger_are_public_arrays(): void
    {
        $user = $this->createAccount('ledger-summary@example.com');
        $service = app(BalanceLedgerService::class);
        $service->credit($user->id, '3.00', 'admin_adjust', null, null, 'Seed', 1);

        $summary = $service->summary($user->id);
        $ledger = $service->ledger($user->id, 10);

        $this->assertSame($user->id, $summary['user_id']);
        $this->assertSame('3.00', $summary['available_balance']);
        $this->assertSame('0.00', $summary['frozen_balance']);
        $this->assertCount(1, $ledger);
        $this->assertSame('admin_adjust', $ledger[0]['type']);
    }

    public function test_affiliate_service_creates_two_level_pending_commissions_for_activation_code(): void
    {
        [$grandparent, $parent, $buyer] = $this->createInviteChain();

        $commissions = app(AffiliateService::class)->createForActivationCode(
            buyerUserId: $buyer->id,
            activationCodeId: 501,
            firstLevelReward: '8.00',
            secondLevelReward: '3.00',
            isCommissionable: true
        );

        $this->assertCount(2, $commissions);
        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'activation_code',
            'source_id' => 501,
            'buyer_user_id' => $buyer->id,
            'beneficiary_user_id' => $parent->id,
            'level' => 1,
            'amount' => '8.00',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'activation_code',
            'source_id' => 501,
            'buyer_user_id' => $buyer->id,
            'beneficiary_user_id' => $grandparent->id,
            'level' => 2,
            'amount' => '3.00',
            'status' => 'pending',
        ]);
    }

    public function test_affiliate_service_is_idempotent_and_skips_non_commissionable_sources(): void
    {
        [, , $buyer] = $this->createInviteChain('idempotent');
        $service = app(AffiliateService::class);

        $this->assertSame([], $service->createForActivationCode($buyer->id, 601, '8.00', '3.00', false));
        $first = $service->createForActivationCode($buyer->id, 602, '8.00', '3.00', true);
        $second = $service->createForActivationCode($buyer->id, 602, '8.00', '3.00', true);

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(
            AffiliateCommission::query()->where('source_type', 'activation_code')->where('source_id', 602)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(2, AffiliateCommission::query()->where('source_type', 'activation_code')->where('source_id', 602)->count());
        $this->assertSame(0, AffiliateCommission::query()->where('source_id', 601)->count());
    }

    public function test_affiliate_service_skips_disabled_or_frozen_beneficiaries(): void
    {
        [$grandparent, $parent, $buyer] = $this->createInviteChain('inactive', parentStatus: 'disabled', grandparentStatus: 'frozen');

        $commissions = app(AffiliateService::class)->createForActivationCode($buyer->id, 701, '8.00', '3.00', true);

        $this->assertSame([], $commissions);
        $this->assertSame(0, AffiliateCommission::query()->count());
        $this->assertSame('disabled', UserAccount::query()->findOrFail($parent->id)->status);
        $this->assertSame('frozen', UserAccount::query()->findOrFail($grandparent->id)->status);
    }

    public function test_affiliate_service_can_create_vip_order_commissions_from_rates(): void
    {
        [$grandparent, $parent, $buyer] = $this->createInviteChain('vip-order');

        $commissions = app(AffiliateService::class)->createForVipOrder(
            buyerUserId: $buyer->id,
            vipOrderId: 801,
            amount: '100.00',
            firstLevelRate: '0.1200',
            secondLevelRate: '0.0300',
            isCommissionable: true
        );

        $this->assertCount(2, $commissions);
        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'vip_order',
            'source_id' => 801,
            'beneficiary_user_id' => $parent->id,
            'level' => 1,
            'amount' => '12.00',
        ]);
        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'vip_order',
            'source_id' => 801,
            'beneficiary_user_id' => $grandparent->id,
            'level' => 2,
            'amount' => '3.00',
        ]);
    }

    public function test_affiliate_service_approves_pending_commission_into_balance_ledger(): void
    {
        [, $parent, $buyer] = $this->createInviteChain('approve');
        app(AffiliateService::class)->createForActivationCode($buyer->id, 901, '8.00', '0.00', true);
        $commission = AffiliateCommission::query()->where('level', 1)->firstOrFail();

        $settled = app(AffiliateService::class)->approve($commission->id, 77);

        $this->assertSame('settled', $settled['status']);
        $this->assertSame(77, $settled['audit_admin_id']);
        $this->assertNotNull($settled['settled_ledger_id']);
        $this->assertDatabaseHas('user_account', [
            'id' => $parent->id,
            'available_balance' => '8.00',
            'frozen_balance' => '0.00',
        ]);
        $this->assertDatabaseHas('user_balance_ledger', [
            'id' => $settled['settled_ledger_id'],
            'user_id' => $parent->id,
            'direction' => 'in',
            'amount' => '8.00',
            'type' => 'affiliate_commission',
            'source_type' => 'affiliate_commission',
            'source_id' => $commission->id,
            'admin_id' => 77,
        ]);
    }

    public function test_affiliate_service_rejects_pending_commission_without_balance_change(): void
    {
        [, $parent, $buyer] = $this->createInviteChain('reject');
        app(AffiliateService::class)->createForActivationCode($buyer->id, 1001, '8.00', '0.00', true);
        $commission = AffiliateCommission::query()->where('level', 1)->firstOrFail();

        try {
            app(AffiliateService::class)->reject($commission->id, ' ', 77);
            $this->fail('Expected blank rejection reason to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Reject reason is required.', $exception->getMessage());
        }

        $rejected = app(AffiliateService::class)->reject($commission->id, 'Fraud risk', 77);

        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('Fraud risk', $rejected['reason']);
        $this->assertSame(77, $rejected['audit_admin_id']);
        $this->assertSame('0.00', (string) UserAccount::query()->findOrFail($parent->id)->available_balance);
        $this->assertSame(0, UserBalanceLedger::query()->where('user_id', $parent->id)->count());
    }

    public function test_affiliate_service_rejects_review_of_non_pending_commission(): void
    {
        [, , $buyer] = $this->createInviteChain('review-state');
        app(AffiliateService::class)->createForActivationCode($buyer->id, 1101, '8.00', '0.00', true);
        $commission = AffiliateCommission::query()->where('level', 1)->firstOrFail();
        app(AffiliateService::class)->reject($commission->id, 'Fraud risk', 77);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only pending commission can be approved.');

        app(AffiliateService::class)->approve($commission->id, 77);
    }

    public function test_activation_redemption_creates_two_level_pending_commissions_for_commissionable_batch(): void
    {
        [$grandparent, $parent, $buyer] = $this->createInviteChain('redeem-commission');
        $plainCode = $this->generateActivationCodeForBatch(isCommissionable: true, firstReward: '6.50', secondReward: '2.25');

        app(ActivationCodeService::class)->redeem([
            'code' => $plainCode,
        ], $buyer->id, '127.0.0.9');

        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'activation_code',
            'buyer_user_id' => $buyer->id,
            'beneficiary_user_id' => $parent->id,
            'level' => 1,
            'amount' => '6.50',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('affiliate_commission', [
            'source_type' => 'activation_code',
            'buyer_user_id' => $buyer->id,
            'beneficiary_user_id' => $grandparent->id,
            'level' => 2,
            'amount' => '2.25',
            'status' => 'pending',
        ]);

        $firstCommissionId = AffiliateCommission::query()->where('level', 1)->value('id');
        $this->assertSame($firstCommissionId, ActivationCodeRedemption::query()->where('result', 'success')->value('commission_source_id'));
    }

    public function test_activation_redemption_does_not_create_commissions_for_non_commissionable_batch(): void
    {
        [, , $buyer] = $this->createInviteChain('redeem-no-commission');
        $plainCode = $this->generateActivationCodeForBatch(isCommissionable: false, firstReward: '6.50', secondReward: '2.25');

        app(ActivationCodeService::class)->redeem([
            'code' => $plainCode,
        ], $buyer->id, '127.0.0.9');

        $this->assertSame(0, AffiliateCommission::query()->count());
        $this->assertNull(ActivationCodeRedemption::query()->where('result', 'success')->value('commission_source_id'));
    }

    public function test_failed_activation_redemption_does_not_create_commission(): void
    {
        [, , $buyer] = $this->createInviteChain('redeem-failed');

        try {
            app(ActivationCodeService::class)->redeem([
                'code' => 'EA8-MISSING-CODE',
            ], $buyer->id, '127.0.0.9');
            $this->fail('Expected invalid activation code to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Activation code is invalid.', $exception->getMessage());
        }

        $this->assertSame(0, AffiliateCommission::query()->count());
        $this->assertNull(ActivationCodeRedemption::query()->where('result', 'failed')->value('commission_source_id'));
    }

    public function test_user_balance_endpoints_return_summary_and_ledger_for_logged_in_user(): void
    {
        $user = $this->createAccount('balance-endpoint@example.com');
        app(BalanceLedgerService::class)->credit($user->id, '12.00', 'admin_adjust', null, null, 'Seed', 1);

        $this->withSession(['user' => ['id' => $user->id, 'email' => $user->email]]);

        $summary = $this->getJson('/user/balance');
        $summary->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.available_balance', '12.00')
            ->assertJsonPath('data.frozen_balance', '0.00');

        $ledger = $this->getJson('/user/balance/ledger?limit=5');
        $ledger->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.type', 'admin_adjust')
            ->assertJsonPath('data.0.amount', '12.00');
    }

    public function test_user_balance_endpoints_require_login(): void
    {
        $this->getJson('/user/balance')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->getJson('/user/balance/ledger')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_user_balance_routes_use_install_guard_and_throttle(): void
    {
        foreach ([
            ['GET', '/user/balance'],
            ['GET', '/user/balance/ledger'],
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

    private function createAccount(
        string $email,
        string $availableBalance = '0.00',
        string $frozenBalance = '0.00',
        string $status = 'active'
    ): UserAccount {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'nickname' => $email,
            'status' => $status,
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createInviteChain(
        string $prefix = 'chain',
        string $parentStatus = 'active',
        string $grandparentStatus = 'active'
    ): array {
        $grandparent = $this->createAccount("{$prefix}-grandparent@example.com", status: $grandparentStatus);
        $parent = $this->createAccount("{$prefix}-parent@example.com", status: $parentStatus);
        $buyer = $this->createAccount("{$prefix}-buyer@example.com");

        UserInviteRelation::query()->create([
            'user_id' => $parent->id,
            'parent_user_id' => $grandparent->id,
            'grandparent_user_id' => null,
            'invite_code_id' => 1,
            'level_path' => (string) $grandparent->id,
            'bind_type' => 'register',
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        UserInviteRelation::query()->create([
            'user_id' => $buyer->id,
            'parent_user_id' => $parent->id,
            'grandparent_user_id' => $grandparent->id,
            'invite_code_id' => 2,
            'level_path' => $grandparent->id.'/'.$parent->id,
            'bind_type' => 'register',
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [$grandparent, $parent, $buyer];
    }

    private function createVipPlan(string $name = 'Affiliate VIP'): VipPlan
    {
        return VipPlan::query()->create([
            'name' => $name,
            'level' => 1,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function generateActivationCodeForBatch(
        bool $isCommissionable,
        string $firstReward,
        string $secondReward
    ): string {
        $plan = $this->createVipPlan();
        $batch = ActivationCodeBatch::query()->create([
            'name' => 'Affiliate Batch',
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'total_count' => 5,
            'generated_count' => 0,
            'status' => 'active',
            'is_commissionable' => $isCommissionable,
            'first_level_reward' => $firstReward,
            'second_level_reward' => $secondReward,
            'create_admin_id' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return app(ActivationCodeService::class)->generateCodes($batch->id, 1, 1)['codes'][0];
    }
}
