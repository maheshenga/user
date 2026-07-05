<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserBalanceLedger;
use App\User\BalanceLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminAffiliateBalanceControllerTest extends TestCase
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

    public function test_admin_commission_index_lists_safe_fields_and_ignores_unsafe_filter_sort(): void
    {
        [$buyer, $beneficiary] = $this->createBuyerAndBeneficiary('commission-index');
        $first = $this->createCommission($buyer, $beneficiary, 1, '8.00', 'pending', 1001);
        $second = $this->createCommission($buyer, $beneficiary, 2, '3.00', 'rejected', 1002);

        $allowed = $this->getJson('/admin/user/commission/index?'.http_build_query([
            'filter' => json_encode(['status' => 'rejected']),
            'op' => json_encode(['status' => '=']),
            'tableOrder' => 'level asc',
        ]));
        $allowed->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $second->id);

        $blocked = $this->getJson('/admin/user/commission/index?'.http_build_query([
            'filter' => json_encode(['reason' => 'hidden']),
            'op' => json_encode(['reason' => '=']),
            'tableOrder' => 'reason asc',
        ]));
        $blocked->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);

        $row = $blocked->json('data.0');
        $this->assertSame([
            'id',
            'source_type',
            'source_id',
            'buyer_user_id',
            'beneficiary_user_id',
            'level',
            'amount',
            'status',
            'audit_admin_id',
            'audited_at',
            'settled_ledger_id',
            'create_time',
        ], array_keys($row));
        $this->assertArrayNotHasKey('reason', $row);
        $this->assertArrayNotHasKey('reversed_commission_id', $row);
    }

    public function test_admin_commission_approve_and_reject_use_admin_session(): void
    {
        [$buyer, $beneficiary] = $this->createBuyerAndBeneficiary('commission-review');
        $approve = $this->createCommission($buyer, $beneficiary, 1, '8.00', 'pending', 1101);
        $reject = $this->createCommission($buyer, $beneficiary, 2, '3.00', 'pending', 1102);

        $approveResponse = $this->postJson('/admin/user/commission/approve', [
            'id' => $approve->id,
        ]);
        $approveResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.audit_admin_id', 77);

        $this->assertDatabaseHas('user_account', [
            'id' => $beneficiary->id,
            'available_balance' => '8.00',
        ]);

        $this->postJson('/admin/user/commission/reject', [
            'id' => $reject->id,
            'reason' => '',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $rejectResponse = $this->postJson('/admin/user/commission/reject', [
            'id' => $reject->id,
            'reason' => 'Manual risk review',
        ]);
        $rejectResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Manual risk review')
            ->assertJsonPath('data.audit_admin_id', 77);
    }

    public function test_admin_balance_index_lists_safe_fields_and_adjusts_with_reason(): void
    {
        $user = $this->createAccount('balance-admin@example.com');
        app(BalanceLedgerService::class)->credit($user->id, '5.00', 'admin_adjust', null, null, 'Seed', 77);

        $index = $this->getJson('/admin/user/balance/index?'.http_build_query([
            'filter' => json_encode(['user_id' => $user->id]),
            'op' => json_encode(['user_id' => '=']),
            'tableOrder' => 'id desc',
        ]));
        $index->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user_id', $user->id);

        $row = $index->json('data.0');
        $this->assertSame([
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
        ], array_keys($row));

        $blocked = $this->getJson('/admin/user/balance/index?'.http_build_query([
            'filter' => json_encode(['remark' => 'Seed']),
            'op' => json_encode(['remark' => '=']),
            'tableOrder' => 'remark asc',
        ]));
        $blocked->assertOk()
            ->assertJsonPath('count', 1);

        $this->postJson('/admin/user/balance/adjust', [
            'user_id' => $user->id,
            'amount' => '2.25',
            'reason' => '',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $adjust = $this->postJson('/admin/user/balance/adjust', [
            'user_id' => $user->id,
            'amount' => '2.25',
            'reason' => 'Manual bonus',
        ]);
        $adjust->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.type', 'admin_adjust')
            ->assertJsonPath('data.admin_id', 77);

        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'available_balance' => '7.25',
        ]);
    }

    public function test_admin_affiliate_balance_controllers_block_unsafe_inherited_actions(): void
    {
        [$buyer, $beneficiary] = $this->createBuyerAndBeneficiary('blocked');
        $commission = $this->createCommission($buyer, $beneficiary, 1, '8.00', 'pending', 1201);
        app(BalanceLedgerService::class)->credit($beneficiary->id, '5.00', 'admin_adjust', null, null, 'Seed', 77);
        $ledger = UserBalanceLedger::query()->firstOrFail();

        foreach ([
            ['postJson', '/admin/user/commission/add', ['id' => $commission->id]],
            ['postJson', '/admin/user/commission/edit', ['id' => $commission->id, 'status' => 'settled']],
            ['postJson', '/admin/user/commission/delete', ['id' => $commission->id]],
            ['postJson', '/admin/user/commission/modify', ['id' => $commission->id, 'field' => 'status', 'value' => 'settled']],
            ['getJson', '/admin/user/commission/recycle', []],
            ['postJson', '/admin/user/balance/add', ['user_id' => $beneficiary->id]],
            ['postJson', '/admin/user/balance/edit', ['id' => $ledger->id, 'amount' => 100]],
            ['postJson', '/admin/user/balance/delete', ['id' => $ledger->id]],
            ['postJson', '/admin/user/balance/modify', ['id' => $ledger->id, 'field' => 'amount', 'value' => 100]],
            ['getJson', '/admin/user/balance/recycle', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertOk()
                ->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/commission/export')->assertForbidden();
        $this->getJson('/admin/user/balance/export')->assertForbidden();

        $this->assertDatabaseHas('affiliate_commission', [
            'id' => $commission->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, UserBalanceLedger::query()->count());
    }

    private function createBuyerAndBeneficiary(string $prefix): array
    {
        return [
            $this->createAccount("{$prefix}-buyer@example.com"),
            $this->createAccount("{$prefix}-beneficiary@example.com"),
        ];
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
            'reason' => $status === 'rejected' ? 'Hidden reason' : '',
            'create_time' => time(),
            'update_time' => time(),
        ]);
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
