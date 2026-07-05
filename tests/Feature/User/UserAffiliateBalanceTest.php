<?php

namespace Tests\Feature\User;

use App\Models\AffiliateCommission;
use App\Models\UserBalanceLedger;
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
}
