<?php

namespace Tests\Feature\User;

use App\Models\UserRiskEvent;
use App\Models\UserAccount;
use App\Models\UserWithdrawalRequest;
use App\User\ActivationCodeService;
use App\User\RiskService;
use App\User\UserAuthService;
use InvalidArgumentException;
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

    private function createAccount(string $email): UserAccount
    {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'nickname' => $email,
            'status' => 'active',
            'available_balance' => '0.00',
            'frozen_balance' => '0.00',
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
