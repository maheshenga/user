<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserSecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminSecurityLogControllerTest extends TestCase
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

    public function test_admin_security_log_index_returns_safe_rows(): void
    {
        UserSecurityLog::query()->create([
            'user_id' => 10,
            'event' => 'password_reset_completed',
            'ip' => '127.0.0.10',
            'user_agent' => 'Feature Test Agent',
            'metadata_json' => ['token' => 'hidden'],
            'create_time' => 1783238400,
        ]);

        $response = $this->getJson('/admin/user/security-log/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user_id', 10)
            ->assertJsonPath('data.0.event', 'password_reset_completed')
            ->assertJsonPath('data.0.ip', '127.0.0.10');

        $row = $response->json('data.0');
        $this->assertSame([
            'id',
            'user_id',
            'event',
            'ip',
            'user_agent',
            'create_time',
        ], array_keys($row));
        $this->assertArrayNotHasKey('metadata_json', $row);
    }

    public function test_admin_security_log_index_ignores_metadata_filter_and_sort(): void
    {
        $first = UserSecurityLog::query()->create([
            'user_id' => 11,
            'event' => 'password_reset_requested',
            'ip' => '127.0.0.11',
            'user_agent' => 'First Agent',
            'metadata_json' => ['secret' => 'alpha'],
            'create_time' => 1783238401,
        ]);
        $second = UserSecurityLog::query()->create([
            'user_id' => 12,
            'event' => 'password_reset_completed',
            'ip' => '127.0.0.12',
            'user_agent' => 'Second Agent',
            'metadata_json' => ['secret' => 'beta'],
            'create_time' => 1783238402,
        ]);

        $allowed = $this->getJson('/admin/user/security-log/index?'.http_build_query([
            'filter' => json_encode(['event' => 'password_reset_completed']),
            'op' => json_encode(['event' => '=']),
            'tableOrder' => 'user_id asc',
        ]));

        $allowed->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $second->id);

        $blocked = $this->getJson('/admin/user/security-log/index?'.http_build_query([
            'filter' => json_encode(['metadata_json' => 'alpha']),
            'op' => json_encode(['metadata_json' => '=']),
            'tableOrder' => 'metadata_json asc',
        ]));

        $blocked->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_admin_security_log_controller_rejects_inherited_write_actions(): void
    {
        $log = UserSecurityLog::query()->create([
            'user_id' => 13,
            'event' => 'password_reset_completed',
            'ip' => '127.0.0.13',
            'user_agent' => 'Read Only Agent',
            'metadata_json' => ['secret' => 'stable'],
            'create_time' => 1783238403,
        ]);

        foreach ([
            ['postJson', '/admin/user/security-log/add', ['event' => 'bad_event']],
            ['postJson', '/admin/user/security-log/edit', ['id' => $log->id, 'event' => 'changed']],
            ['postJson', '/admin/user/security-log/delete', ['id' => $log->id]],
            ['postJson', '/admin/user/security-log/modify', ['id' => $log->id, 'field' => 'event', 'value' => 'changed']],
            ['getJson', '/admin/user/security-log/recycle', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->{$method}($uri, $payload);

            $response->assertOk()
                ->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/security-log/export')->assertForbidden();

        $this->assertDatabaseHas('user_security_log', [
            'id' => $log->id,
            'event' => 'password_reset_completed',
            'ip' => '127.0.0.13',
        ]);
        $this->assertDatabaseMissing('user_security_log', [
            'event' => 'bad_event',
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
