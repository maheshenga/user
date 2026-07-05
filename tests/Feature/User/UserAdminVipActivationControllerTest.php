<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\VipPlan;
use App\User\ActivationCodeService;
use App\User\UserAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminVipActivationControllerTest extends TestCase
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
            RateLimiting::class,
            SystemLog::class,
        ]);

        $this->withSession([
            'admin.id' => 1,
            'admin.expire_time' => true,
        ]);
    }

    public function test_admin_vip_plan_can_add_edit_modify_and_list_safe_fields(): void
    {
        $created = $this->postJson('/admin/user/vip-plan/add', [
            'name' => 'Gold VIP',
            'level' => 2,
            'duration_days' => 30,
            'price' => 99.90,
            'status' => 'active',
            'is_commissionable' => 1,
            'first_level_rate' => 0.1000,
            'second_level_rate' => 0.0500,
            'benefits_json' => ['internal' => 'hidden'],
        ]);

        $created->assertOk()
            ->assertJsonPath('code', 1);
        $planId = (int) $created->json('data.id');

        $this->postJson('/admin/user/vip-plan/edit', [
            'id' => $planId,
            'name' => 'Gold VIP Plus',
            'price' => 129.90,
            'benefits_json' => ['internal' => 'updated'],
            'delete_time' => 123,
        ])->assertOk()
            ->assertJsonPath('code', 1);

        $this->postJson('/admin/user/vip-plan/modify', [
            'id' => $planId,
            'field' => 'status',
            'value' => 'disabled',
        ])->assertOk()
            ->assertJsonPath('code', 1);

        $response = $this->getJson('/admin/user/vip-plan/index');
        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.name', 'Gold VIP Plus')
            ->assertJsonPath('data.0.status', 'disabled');

        $row = $response->json('data.0');
        $this->assertSame([
            'id',
            'name',
            'level',
            'duration_days',
            'price',
            'status',
            'is_commissionable',
            'first_level_rate',
            'second_level_rate',
            'create_time',
        ], array_keys($row));
        $this->assertArrayNotHasKey('benefits_json', $row);
        $this->assertArrayNotHasKey('delete_time', $row);

        $this->assertDatabaseHas('vip_plan', [
            'id' => $planId,
            'name' => 'Gold VIP Plus',
            'status' => 'disabled',
        ]);
        $this->assertDatabaseMissing('vip_plan', [
            'id' => $planId,
            'delete_time' => 123,
        ]);
    }

    public function test_admin_vip_plan_ignores_unsafe_filter_sort_and_blocks_delete_export(): void
    {
        $first = $this->createVipPlan('Alpha VIP', 1);
        $second = $this->createVipPlan('Beta VIP', 2);

        $allowed = $this->getJson('/admin/user/vip-plan/index?'.http_build_query([
            'filter' => json_encode(['level' => 2]),
            'op' => json_encode(['level' => '=']),
            'tableOrder' => 'name asc',
        ]));
        $allowed->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $second->id);

        $blocked = $this->getJson('/admin/user/vip-plan/index?'.http_build_query([
            'filter' => json_encode(['benefits_json' => 'secret']),
            'op' => json_encode(['benefits_json' => '=']),
            'tableOrder' => 'benefits_json asc',
        ]));
        $blocked->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);

        $this->postJson('/admin/user/vip-plan/delete', ['id' => $first->id])
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->getJson('/admin/user/vip-plan/recycle')
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->getJson('/admin/user/vip-plan/export')->assertForbidden();
    }

    public function test_admin_activation_code_batch_generate_and_safe_index(): void
    {
        $plan = $this->createVipPlan('Activation VIP', 3);

        $batchResponse = $this->postJson('/admin/user/activation-code/createBatch', [
            'name' => 'July Codes',
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'total_count' => 3,
            'status' => 'active',
            'is_commissionable' => 1,
            'first_level_reward' => 10,
            'second_level_reward' => 5,
        ]);
        $batchResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.total_count', 3);

        $generateResponse = $this->postJson('/admin/user/activation-code/generate', [
            'batch_id' => $batchResponse->json('data.id'),
            'count' => 2,
        ]);
        $generateResponse->assertOk()
            ->assertJsonPath('code', 1);
        $plainCodes = $generateResponse->json('data.codes');
        $this->assertCount(2, $plainCodes);

        $storedCode = ActivationCode::query()->firstOrFail();
        $this->assertNotSame($plainCodes[0], $storedCode->code_hash);
        $this->assertSame(2, (int) ActivationCodeBatch::query()->firstOrFail()->generated_count);

        $index = $this->getJson('/admin/user/activation-code/index');
        $index->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 2);

        $row = $index->json('data.0');
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
    }

    public function test_admin_activation_code_status_actions_and_redemptions_are_safe(): void
    {
        $plan = $this->createVipPlan('Activation VIP', 2);
        $batch = app(ActivationCodeService::class)->createBatch([
            'name' => 'Redeem Batch',
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'total_count' => 3,
            'status' => 'active',
        ], 1);
        app(ActivationCodeService::class)->generateCodes($batch['id'], 2, 1);
        $firstCode = ActivationCode::query()->orderBy('id')->firstOrFail();
        $secondCode = ActivationCode::query()->orderBy('id')->skip(1)->firstOrFail();

        $this->postJson('/admin/user/activation-code/disable', ['id' => $firstCode->id])
            ->assertOk()
            ->assertJsonPath('code', 1);
        $this->postJson('/admin/user/activation-code/void', ['id' => $secondCode->id])
            ->assertOk()
            ->assertJsonPath('code', 1);

        $this->assertDatabaseHas('activation_code', ['id' => $firstCode->id, 'status' => 'disabled']);
        $this->assertDatabaseHas('activation_code', ['id' => $secondCode->id, 'status' => 'void']);

        $user = app(UserAuthService::class)->register([
            'email' => 'admin-redemption@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $freshGenerated = app(ActivationCodeService::class)->generateCodes($batch['id'], 1, 1);
        app(ActivationCodeService::class)->redeem([
            'code' => $freshGenerated['codes'][0],
        ], $user['user']['id'], '127.0.0.9');

        $redemptions = $this->getJson('/admin/user/activation-code/redemptions');
        $redemptions->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user_id', $user['user']['id'])
            ->assertJsonPath('data.0.result', 'success');

        $row = $redemptions->json('data.0');
        $this->assertArrayNotHasKey('code_hash', $row);
        $this->assertArrayNotHasKey('error_message', $row);

        foreach ([
            ['postJson', '/admin/user/activation-code/add', ['code' => 'BAD']],
            ['postJson', '/admin/user/activation-code/edit', ['id' => $firstCode->id, 'status' => 'unused']],
            ['postJson', '/admin/user/activation-code/delete', ['id' => $firstCode->id]],
            ['postJson', '/admin/user/activation-code/modify', ['id' => $firstCode->id, 'field' => 'code_hash', 'value' => 'leak']],
            ['getJson', '/admin/user/activation-code/recycle', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertOk()
                ->assertJsonPath('code', 0);
        }
        $export = $this->getJson('/admin/user/activation-code/export');
        $export->assertOk()
            ->assertJsonPath('code', 1);
        $exportRow = $export->json('data.rows.0');
        $this->assertArrayNotHasKey('code_hash', $exportRow);
        $this->assertArrayNotHasKey('code', $exportRow);

        $this->assertSame(1, ActivationCodeRedemption::query()->where('result', 'success')->count());
    }

    private function createVipPlan(string $name, int $level): VipPlan
    {
        return VipPlan::query()->create([
            'name' => $name,
            'level' => $level,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'is_commissionable' => false,
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
