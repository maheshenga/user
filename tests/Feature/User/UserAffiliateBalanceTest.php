<?php

namespace Tests\Feature\User;

use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserBalanceLedger;
use App\User\BalanceLedgerService;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
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
}
