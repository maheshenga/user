<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\User\BalanceLedgerService;
use App\User\BalanceReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UserBalanceReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_reconciliation_accepts_service_created_balance_history(): void
    {
        $user = $this->createAccount('balance-reconcile-clean@example.com');
        $balance = app(BalanceLedgerService::class);
        $balance->credit($user->id, '10.00', 'seed', 'test_source', 1, 'Seed');
        $balance->freeze($user->id, '3.00', 'withdraw_freeze', 'withdrawal', 1, 'Freeze');

        $result = app(BalanceReconciliationService::class)->inspect($user->id);

        $this->assertSame(1, $result['checked_users']);
        $this->assertSame(0, $result['issue_count']);
        $this->assertSame([], $result['issues']);
        $this->artisan('user:balance:reconcile', ['--user' => $user->id])->assertExitCode(0);
    }

    public function test_reconciliation_reports_tampered_account_snapshot(): void
    {
        $user = $this->createAccount('balance-reconcile-tampered@example.com');
        app(BalanceLedgerService::class)->credit($user->id, '5.00', 'seed', 'test_source', 2, 'Seed');
        DB::table('user_account')->where('id', $user->id)->update(['available_balance' => '6.00']);

        $result = app(BalanceReconciliationService::class)->inspect($user->id);

        $this->assertSame(1, $result['issue_count']);
        $this->assertSame('account_snapshot_mismatch', $result['issues'][0]['code']);
        $this->assertSame('5.00/0.00', $result['issues'][0]['expected']);
        $this->assertSame('6.00/0.00', $result['issues'][0]['actual']);
        $this->artisan('user:balance:reconcile', ['--user' => $user->id])
            ->expectsOutputToContain('account_snapshot_mismatch')
            ->assertExitCode(1);
    }

    public function test_reconciliation_reports_nonzero_account_without_ledger(): void
    {
        $user = $this->createAccount('balance-reconcile-missing@example.com', '8.00');

        $result = app(BalanceReconciliationService::class)->inspect($user->id);

        $this->assertSame(1, $result['issue_count']);
        $this->assertSame('missing_ledger', $result['issues'][0]['code']);
        $this->assertNull($result['issues'][0]['ledger_id']);
    }

    public function test_reconciliation_reports_broken_ledger_continuity(): void
    {
        $user = $this->createAccount('balance-reconcile-continuity@example.com');
        $balance = app(BalanceLedgerService::class);
        $balance->credit($user->id, '5.00', 'seed', 'test_source', 3, 'Seed');
        $second = $balance->credit($user->id, '2.00', 'bonus', 'test_source', 4, 'Bonus');
        DB::table('user_balance_ledger')->where('id', $second['id'])->update(['balance_before' => '4.00']);

        $result = app(BalanceReconciliationService::class)->inspect($user->id);

        $this->assertSame(1, $result['issue_count']);
        $this->assertSame('ledger_continuity_mismatch', $result['issues'][0]['code']);
        $this->assertSame('5.00/0.00', $result['issues'][0]['expected']);
        $this->assertSame('4.00/0.00', $result['issues'][0]['actual']);
    }

    public function test_reconciliation_command_rejects_invalid_user_option(): void
    {
        $this->artisan('user:balance:reconcile', ['--user' => 'invalid'])
            ->expectsOutput('用户 ID 必须是正整数。')
            ->assertExitCode(1);
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
}
