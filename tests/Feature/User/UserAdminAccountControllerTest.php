<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminAccountControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

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

    public function test_admin_user_account_index_returns_rows(): void
    {
        UserAccount::query()->create([
            'mobile' => '13800138000',
            'email' => 'admin-list@example.com',
            'password' => 'secret123',
            'nickname' => 'List User',
            'register_ip' => '127.0.0.1',
            'last_login_at' => Carbon::create(2026, 7, 5, 9, 30, 0),
            'last_login_ip' => '127.0.0.2',
            'available_balance' => 12.34,
            'frozen_balance' => 5.67,
            'vip_level' => 2,
        ]);

        $response = $this->getJson('/admin/user/account/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mobile', '13800138000')
            ->assertJsonPath('data.0.email', 'admin-list@example.com');
    }

    public function test_admin_user_account_index_returns_only_safe_list_columns(): void
    {
        UserAccount::query()->create([
            'mobile' => '13800138002',
            'email' => 'safe-list@example.com',
            'password' => 'secret123',
            'nickname' => 'Safe List User',
            'register_channel' => 'internal-import',
            'register_ip' => '10.0.0.10',
            'last_login_at' => Carbon::create(2026, 7, 5, 10, 30, 0),
            'last_login_ip' => '10.0.0.11',
            'available_balance' => 20.25,
            'frozen_balance' => 99.99,
            'vip_level' => 1,
        ]);

        $response = $this->getJson('/admin/user/account/index');

        $response->assertOk();

        $row = $response->json('data.0');

        $this->assertSame([
            'id',
            'mobile',
            'email',
            'nickname',
            'status',
            'vip_level',
            'available_balance',
            'last_login_at',
        ], array_keys($row));
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('register_ip', $row);
        $this->assertArrayNotHasKey('last_login_ip', $row);
        $this->assertArrayNotHasKey('register_channel', $row);
        $this->assertArrayNotHasKey('mobile_verified_at', $row);
        $this->assertArrayNotHasKey('email_verified_at', $row);
        $this->assertArrayNotHasKey('frozen_balance', $row);
    }

    public function test_admin_user_account_index_allows_safe_search_and_sort_only(): void
    {
        $first = UserAccount::query()->create([
            'mobile' => '13800138003',
            'email' => 'alpha@example.com',
            'password' => 'secret123',
            'nickname' => 'Alpha',
            'status' => 'active',
            'register_ip' => '10.0.0.20',
            'vip_level' => 1,
        ]);
        $second = UserAccount::query()->create([
            'mobile' => '13800138004',
            'email' => 'beta@example.com',
            'password' => 'secret123',
            'nickname' => 'Beta',
            'status' => 'pending',
            'register_ip' => '10.0.0.21',
            'vip_level' => 2,
        ]);

        $allowed = $this->getJson('/admin/user/account/index?'.http_build_query([
            'filter' => json_encode(['status' => 'pending']),
            'op' => json_encode(['status' => '=']),
            'tableOrder' => 'nickname asc',
        ]));

        $allowed->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $second->id);

        $blocked = $this->getJson('/admin/user/account/index?'.http_build_query([
            'filter' => json_encode(['register_ip' => '10.0.0.20']),
            'op' => json_encode(['register_ip' => '=']),
            'tableOrder' => 'register_ip asc',
        ]));

        $blocked->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_admin_user_account_index_requires_seeded_permission_for_non_super_admin(): void
    {
        UserAccount::query()->create([
            'mobile' => '13800138005',
            'email' => 'auth-boundary@example.com',
            'password' => 'secret123',
            'nickname' => 'Auth Boundary',
        ]);
        DB::table('system_admin')->updateOrInsert(
            ['id' => 2],
            ['status' => 1, 'auth_ids' => '10']
        );
        DB::table('system_node')->insert([
            'id' => 101,
            'node' => 'user/account/index',
            'title' => 'User Account Index',
            'type' => 2,
            'is_auth' => 1,
        ]);
        DB::table('system_auth_node')->insert([
            'auth_id' => 10,
            'node_id' => 101,
        ]);

        $this->withSession([
            'admin.id' => 2,
            'admin.expire_time' => true,
        ]);

        $allowed = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/admin/user/account/index');

        $allowed->assertOk()
            ->assertJsonPath('count', 1);

        DB::table('system_auth_node')->where('auth_id', 10)->delete();

        $denied = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/admin/user/account/index');

        $denied->assertOk();
        $denied->assertJsonMissingPath('count');
        $denied->assertJsonMissingPath('data.0.email');
    }

    public function test_admin_user_account_detail_renders_user(): void
    {
        $user = UserAccount::query()->create([
            'mobile' => '13800138001',
            'email' => 'admin-detail@example.com',
            'password' => 'secret123',
            'nickname' => 'Detail User',
            'register_ip' => '127.0.0.1',
            'available_balance' => 12.34,
            'frozen_balance' => 5.67,
            'vip_level' => 3,
        ]);

        $response = $this->get('/admin/user/account/detail?id='.$user->id);

        $response->assertOk();
        $response->assertSee('admin-detail@example.com');
        $response->assertSee('Detail User');
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 120)->default('');
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
