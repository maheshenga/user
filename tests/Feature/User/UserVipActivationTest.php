<?php

namespace Tests\Feature\User;

use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\UserVipRecord;
use App\Models\VipPlan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserVipActivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
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
}
