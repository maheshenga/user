<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Models\UserRiskEvent;
use App\Models\UserAccount;
use App\Models\UserWithdrawalRequest;
use App\User\ActivationCodeService;
use App\User\BalanceLedgerService;
use App\User\RiskService;
use App\User\UserAuthService;
use App\User\WithdrawalService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRiskOpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_risk_ops_phase_6_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_risk_event'));
        $this->assertTrue(Schema::hasTable('user_withdrawal_request'));

        $this->assertTrue(Schema::hasColumns('user_risk_event', [
            'id',
            'user_id',
            'category',
            'event_type',
            'severity',
            'source_type',
            'source_id',
            'ip',
            'status',
            'detail_json',
            'review_admin_id',
            'reviewed_at',
            'create_time',
            'update_time',
        ]));

        $this->assertTrue(Schema::hasColumns('user_withdrawal_request', [
            'id',
            'withdrawal_no',
            'user_id',
            'amount',
            'status',
            'request_ip',
            'account_snapshot_json',
            'ledger_freeze_id',
            'ledger_success_id',
            'reason',
            'audit_admin_id',
            'audited_at',
            'create_time',
            'update_time',
        ]));

        $event = UserRiskEvent::query()->create([
            'user_id' => 1,
            'category' => 'invite',
            'event_type' => 'invite_burst',
            'severity' => 'medium',
            'source_type' => 'user_invite_relation',
            'source_id' => 2,
            'ip' => '127.0.0.1',
            'status' => 'open',
            'detail_json' => ['count' => 10],
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $withdrawal = UserWithdrawalRequest::query()->create([
            'withdrawal_no' => 'WD202607050001',
            'user_id' => 1,
            'amount' => '12.50',
            'status' => 'pending',
            'request_ip' => '127.0.0.1',
            'account_snapshot_json' => ['account_no' => 'masked-001'],
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->assertSame(['count' => 10], $event->refresh()->detail_json);
        $this->assertSame(['account_no' => 'masked-001'], $withdrawal->refresh()->account_snapshot_json);
        $this->assertSame('12.50', $withdrawal->amount);
    }

    public function test_withdrawal_payout_ops_fields_exist_and_cast_json(): void
    {
        $this->assertTrue(Schema::hasColumns('user_withdrawal_request', [
            'approved_admin_id',
            'approved_at',
            'payout_admin_id',
            'payout_method',
            'payout_transaction_id',
            'payout_proof_json',
            'payout_error',
            'payout_attempt_count',
            'payout_last_attempt_at',
            'paid_at',
        ]));

        $withdrawal = UserWithdrawalRequest::query()->create([
            'withdrawal_no' => 'WD202607050002',
            'user_id' => 1,
            'amount' => '18.50',
            'status' => 'approved',
            'request_ip' => '127.0.0.1',
            'account_snapshot_json' => ['account_no' => 'masked-002'],
            'payout_proof_json' => ['receipt_url' => 'https://example.test/receipt/1'],
            'payout_attempt_count' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->assertSame(['receipt_url' => 'https://example.test/receipt/1'], $withdrawal->refresh()->payout_proof_json);
        $this->assertSame(1, (int) $withdrawal->payout_attempt_count);
    }

    public function test_invite_burst_risk_event_is_created_once_after_threshold(): void
    {
        $auth = app(UserAuthService::class);
        $parent = $auth->register([
            'email' => 'risk-parent@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $lastChild = null;
        for ($i = 1; $i <= 5; $i++) {
            $lastChild = $auth->register([
                'email' => "risk-child-{$i}@example.com",
                'password' => 'secret123',
                'invite_code' => $parent['invite_code']['code'],
            ], '127.0.0.9');
        }

        $this->assertNotNull($lastChild);
        $this->assertDatabaseHas('user_risk_event', [
            'user_id' => $lastChild['user']['id'],
            'category' => 'invite',
            'event_type' => 'invite_burst',
            'severity' => 'medium',
            'status' => 'open',
        ]);

        app(RiskService::class)->evaluateInviteRegistration($lastChild['user']['id']);

        $this->assertSame(1, UserRiskEvent::query()
            ->where('user_id', $lastChild['user']['id'])
            ->where('event_type', 'invite_burst')
            ->count());
    }

    public function test_activation_failure_records_risk_event_without_secret_material(): void
    {
        $user = $this->createAccount('risk-activation@example.com');

        try {
            app(ActivationCodeService::class)->redeem([
                'code' => 'EA8-SECRET-CODE',
            ], $user->id, '127.0.0.8');
            $this->fail('Expected invalid activation code to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Activation code is invalid.', $exception->getMessage());
        }

        $event = UserRiskEvent::query()->where('event_type', 'activation_code_failed')->firstOrFail();

        $this->assertSame($user->id, (int) $event->user_id);
        $this->assertSame('activation_code', $event->category);
        $this->assertSame('low', $event->severity);
        $this->assertSame('127.0.0.8', $event->ip);
        $encoded = json_encode($event->detail_json);
        $this->assertStringNotContainsString('EA8-SECRET-CODE', $encoded);
        $this->assertStringNotContainsString('code_hash', $encoded);
    }

    public function test_withdrawal_service_request_freezes_available_balance(): void
    {
        $user = $this->createAccount('withdraw-request@example.com', '100.00');

        $request = app(WithdrawalService::class)->request($user->id, '10.00', [
            'account_name' => 'Alice',
            'account_no' => 'masked-001',
        ], '127.0.0.6');

        $this->assertSame('pending', $request['status']);
        $this->assertSame('10.00', $request['amount']);
        $this->assertNotNull($request['ledger_freeze_id']);
        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '90.00',
            'frozen_balance' => '10.00',
        ]);
        $this->assertDatabaseHas('user_balance_ledger', [
            'id' => $request['ledger_freeze_id'],
            'user_id' => $user->id,
            'direction' => 'freeze',
            'amount' => '10.00',
            'type' => 'withdraw_freeze',
            'source_type' => 'user_withdrawal_request',
        ]);
    }

    public function test_withdrawal_service_rejects_invalid_or_insufficient_amounts(): void
    {
        $user = $this->createAccount('withdraw-invalid@example.com', '5.00');
        $service = app(WithdrawalService::class);

        foreach (['0', '-1', ''] as $amount) {
            try {
                $service->request($user->id, $amount, ['account_no' => 'masked'], '127.0.0.6');
                $this->fail("Expected withdrawal amount [{$amount}] to fail.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Amount must be greater than zero.', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Available balance is insufficient.');

        $service->request($user->id, '6.00', ['account_no' => 'masked'], '127.0.0.6');
    }

    public function test_balance_ledger_service_settles_frozen_balance(): void
    {
        $user = $this->createAccount('withdraw-settle@example.com', '20.00');
        app(BalanceLedgerService::class)->freeze($user->id, '7.00', 'withdraw_freeze', null, null, 'Hold', 1);

        $ledger = app(BalanceLedgerService::class)->settleFrozen(
            $user->id,
            '7.00',
            'withdraw_success',
            'user_withdrawal_request',
            15,
            'Paid',
            9
        );

        $this->assertSame('out', $ledger['direction']);
        $this->assertSame('13.00', $ledger['balance_after']);
        $this->assertSame('0.00', $ledger['frozen_after']);
        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '13.00',
            'frozen_balance' => '0.00',
        ]);
    }

    public function test_withdrawal_service_admin_approve_and_reject_review_pending_requests(): void
    {
        $approvedUser = $this->createAccount('withdraw-approve@example.com', '50.00');
        $rejectedUser = $this->createAccount('withdraw-reject@example.com', '50.00');
        $service = app(WithdrawalService::class);

        $approve = $service->request($approvedUser->id, '12.00', ['account_no' => 'approve'], '127.0.0.6');
        $reject = $service->request($rejectedUser->id, '9.00', ['account_no' => 'reject'], '127.0.0.6');

        $paid = $service->approve($approve['id'], 7);
        $this->assertSame('paid', $paid['status']);
        $this->assertNotNull($paid['ledger_success_id']);
        $this->assertDatabaseHas('user_account', [
            'id' => $approvedUser->id,
            'available_balance' => '38.00',
            'frozen_balance' => '0.00',
        ]);

        try {
            $service->reject($reject['id'], ' ', 7);
            $this->fail('Expected blank reject reason to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Reject reason is required.', $exception->getMessage());
        }

        $rejected = $service->reject($reject['id'], 'Manual risk review', 7);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('Manual risk review', $rejected['reason']);
        $this->assertDatabaseHas('user_account', [
            'id' => $rejectedUser->id,
            'available_balance' => '50.00',
            'frozen_balance' => '0.00',
        ]);
    }

    public function test_user_withdrawal_endpoints_require_login_and_return_user_rows(): void
    {
        $user = $this->createAccount('withdraw-endpoint@example.com', '30.00');

        $this->postJson('/user/withdrawal/request', [
            'amount' => '5.00',
            'account' => ['account_no' => 'masked'],
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $this->withSession(['user' => ['id' => $user->id, 'email' => $user->email]]);

        $request = $this->postJson('/user/withdrawal/request', [
            'amount' => '5.00',
            'account' => ['account_no' => 'masked'],
        ]);
        $request->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '5.00');

        $list = $this->getJson('/user/withdrawal');
        $list->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_user_withdrawal_routes_use_install_guard_and_throttle(): void
    {
        foreach ([
            ['POST', '/user/withdrawal/request'],
            ['GET', '/user/withdrawal'],
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
        string $frozenBalance = '0.00'
    ): UserAccount
    {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'nickname' => $email,
            'status' => 'active',
            'available_balance' => $availableBalance,
            'frozen_balance' => $frozenBalance,
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
