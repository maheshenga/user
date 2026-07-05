<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserInviteCode;
use App\User\UserAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminInviteControllerTest extends TestCase
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

    public function test_admin_invite_index_returns_safe_code_rows(): void
    {
        $user = app(UserAuthService::class)->register([
            'mobile' => '13910000001',
            'password' => 'secret123',
        ], '127.0.0.1');

        UserInviteCode::query()->whereKey($user['invite_code']['id'])->update([
            'metadata_json' => ['secret' => 'hidden'],
        ]);

        $response = $this->getJson('/admin/user/invite/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.owner_user_id', $user['user']['id'])
            ->assertJsonPath('data.0.code', $user['invite_code']['code']);

        $row = $response->json('data.0');
        $this->assertSame([
            'id',
            'owner_user_id',
            'code',
            'type',
            'status',
            'max_uses',
            'used_count',
            'expires_at',
            'create_time',
        ], array_keys($row));
        $this->assertArrayNotHasKey('metadata_json', $row);
        $this->assertArrayNotHasKey('delete_time', $row);
    }

    public function test_admin_invite_relations_returns_rows(): void
    {
        $auth = app(UserAuthService::class);
        $parent = $auth->register([
            'email' => 'admin-parent@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $child = $auth->register([
            'email' => 'admin-child@example.com',
            'password' => 'secret123',
            'invite_code' => $parent['invite_code']['code'],
        ], '127.0.0.1');

        $response = $this->getJson('/admin/user/invite/relations');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user_id', $child['user']['id'])
            ->assertJsonPath('data.0.parent_user_id', $parent['user']['id']);
    }

    public function test_admin_invite_index_ignores_unsafe_filter_and_sort(): void
    {
        app(UserAuthService::class)->register([
            'mobile' => '13910000002',
            'password' => 'secret123',
        ], '127.0.0.1');
        app(UserAuthService::class)->register([
            'mobile' => '13910000003',
            'password' => 'secret123',
        ], '127.0.0.1');

        $response = $this->getJson('/admin/user/invite/index?'.http_build_query([
            'filter' => json_encode(['metadata_json' => 'hidden']),
            'op' => json_encode(['metadata_json' => '=']),
            'tableOrder' => 'metadata_json asc',
        ]));

        $response->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_admin_invite_controller_rejects_inherited_write_actions(): void
    {
        $user = app(UserAuthService::class)->register([
            'mobile' => '13910000004',
            'password' => 'secret123',
        ], '127.0.0.1');

        foreach ([
            ['postJson', '/admin/user/invite/add', ['code' => 'BAD']],
            ['postJson', '/admin/user/invite/edit', ['id' => $user['invite_code']['id'], 'status' => 'disabled']],
            ['postJson', '/admin/user/invite/delete', ['id' => $user['invite_code']['id']]],
            ['postJson', '/admin/user/invite/modify', ['id' => $user['invite_code']['id'], 'field' => 'status', 'value' => 'disabled']],
            ['getJson', '/admin/user/invite/recycle', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->{$method}($uri, $payload);

            $response->assertOk()
                ->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/invite/export')->assertForbidden();

        $this->assertDatabaseHas('user_invite_code', [
            'id' => $user['invite_code']['id'],
            'status' => 'active',
            'delete_time' => null,
        ]);
        $this->assertDatabaseMissing('user_invite_code', [
            'code' => 'BAD',
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
