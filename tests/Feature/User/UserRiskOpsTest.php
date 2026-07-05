<?php

namespace Tests\Feature\User;

use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;
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
}
