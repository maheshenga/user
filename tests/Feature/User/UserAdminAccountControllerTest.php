<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserAccount;
use App\User\UserAccountStatus;
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

    public function test_admin_user_account_index_supports_last_login_datetime_filter(): void
    {
        UserAccount::query()->create([
            'mobile' => '13800138006',
            'email' => 'early-login@example.com',
            'password' => 'secret123',
            'nickname' => 'Early Login',
            'last_login_at' => Carbon::create(2026, 7, 5, 8, 0, 0),
        ]);
        $matched = UserAccount::query()->create([
            'mobile' => '13800138007',
            'email' => 'matched-login@example.com',
            'password' => 'secret123',
            'nickname' => 'Matched Login',
            'last_login_at' => Carbon::create(2026, 7, 5, 10, 30, 0),
        ]);

        $response = $this->getJson('/admin/user/account/index?'.http_build_query([
            'filter' => json_encode(['last_login_at' => '2026-07-05 10:00:00 - 2026-07-05 11:00:00']),
            'op' => json_encode(['last_login_at' => 'datetime']),
        ]));

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $matched->id);
    }

    public function test_admin_user_account_index_ignores_malformed_table_order(): void
    {
        $first = UserAccount::query()->create([
            'mobile' => '13800138008',
            'email' => 'first-order@example.com',
            'password' => 'secret123',
            'nickname' => 'First Order',
        ]);
        $second = UserAccount::query()->create([
            'mobile' => '13800138009',
            'email' => 'second-order@example.com',
            'password' => 'secret123',
            'nickname' => 'Second Order',
        ]);

        $response = $this->getJson('/admin/user/account/index?tableOrder=nickname');

        $response->assertOk()
            ->assertJsonPath('code', 0)
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

        $denied->assertOk()
            ->assertJsonPath('code', 0);
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

    public function test_admin_user_account_index_exposes_status_management_ui_hooks(): void
    {
        $response = $this->get('/admin/user/account/index');

        $response->assertOk();
        $response->assertSee('账号状态管理');
        $response->assertSee('data-status-endpoint="/admin/user/account/modify"', false);
        $response->assertSee('data-status-values="pending,active,disabled,frozen"', false);
        $response->assertSee('data-auth-modify="1"', false);
        $response->assertSee('待审核');
        $response->assertSee('正常');
        $response->assertSee('已禁用');
        $response->assertSee('已冻结');
        $response->assertSee('id="userStatusTpl"', false);
    }

    public function test_admin_user_account_js_wires_status_table_actions(): void
    {
        $script = file_get_contents(public_path('static/admin/js/user/account.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("modify_url: 'user/account/modify'", $script);
        $this->assertStringContainsString("templet: '#userStatusTpl'", $script);
        $this->assertStringContainsString('data-status-endpoint', $script);
        $this->assertStringContainsString('data-auth-modify', $script);
        $this->assertStringContainsString('data-account-status', $script);
        $this->assertStringContainsString("CONFIG.IS_SUPER_ADMIN === '1'", $script);
        $this->assertStringContainsString("field: 'status'", $script);
        $this->assertStringContainsString('value: status', $script);
        $this->assertStringContainsString('ea.table.reload(init.table_render_id)', $script);
        $this->assertStringNotContainsString("edit_url: 'user/account/edit'", $script);
        $this->assertStringNotContainsString("delete_url: 'user/account/delete'", $script);
    }

    public function test_user_admin_smoke_script_checks_account_status_ui_and_js(): void
    {
        $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

        $this->assertIsString($script);
        $this->assertStringContainsString('expectAccountStatusPage', $script);
        $this->assertStringContainsString('expectAccountStatusScript', $script);
        $this->assertStringContainsString('data-status-endpoint="/admin/user/account/modify"', $script);
        $this->assertStringContainsString('data-auth-modify=', $script);
        $this->assertStringContainsString('id="userStatusTpl"', $script);
        $this->assertStringContainsString('data-account-status', $script);
        $this->assertStringContainsString("field: 'status'", $script);
        $this->assertStringContainsString('value: status', $script);
    }

    public function test_user_admin_smoke_script_checks_account_status_endpoint_guards(): void
    {
        $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

        $this->assertIsString($script);
        $this->assertStringContainsString('expectAccountStatusEndpointGuards', $script);
        $this->assertStringContainsString('adminPath($prefix, \'user/account/modify\')', $script);
        $this->assertStringContainsString("'field' => 'nickname'", $script);
        $this->assertStringContainsString("'field' => 'status'", $script);
        $this->assertStringContainsString("'value' => 'archived'", $script);
        $this->assertStringContainsString('用户账号管理仅允许修改账号状态', $script);
        $this->assertStringContainsString('账号状态值无效', $script);
    }

    public function test_admin_user_account_modify_allows_status_updates_only(): void
    {
        $user = UserAccount::query()->create([
            'mobile' => '13800138012',
            'email' => 'status-update@example.com',
            'password' => 'secret123',
            'nickname' => 'Status User',
            'status' => UserAccountStatus::ACTIVE,
        ]);

        $this->postJson('/admin/user/account/modify', [
            'id' => $user->id,
            'field' => 'status',
            'value' => UserAccountStatus::DISABLED,
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('msg', '保存成功');

        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'status' => UserAccountStatus::DISABLED,
        ]);

        $this->postJson('/admin/user/account/modify', [
            'id' => $user->id,
            'field' => 'status',
            'value' => UserAccountStatus::ACTIVE,
        ])->assertOk()
            ->assertJsonPath('code', 1);

        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'status' => UserAccountStatus::ACTIVE,
        ]);
    }

    public function test_admin_user_account_modify_rejects_non_status_fields_and_invalid_statuses(): void
    {
        $user = UserAccount::query()->create([
            'mobile' => '13800138013',
            'email' => 'status-guard@example.com',
            'password' => 'secret123',
            'nickname' => 'Guarded Name',
            'status' => UserAccountStatus::ACTIVE,
        ]);

        $this->postJson('/admin/user/account/modify', [
            'id' => $user->id,
            'field' => 'nickname',
            'value' => 'Changed Name',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '用户账号管理仅允许修改账号状态。');

        $this->postJson('/admin/user/account/modify', [
            'id' => $user->id,
            'field' => 'status',
            'value' => 'archived',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '账号状态值无效。');

        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'nickname' => 'Guarded Name',
            'status' => UserAccountStatus::ACTIVE,
        ]);
    }

    public function test_admin_user_account_controller_rejects_inherited_write_actions(): void
    {
        $user = UserAccount::query()->create([
            'mobile' => '13800138010',
            'email' => 'readonly@example.com',
            'password' => 'secret123',
            'nickname' => 'Read Only User',
        ]);

        foreach ([
            ['postJson', '/admin/user/account/add', ['mobile' => '13800138011', 'password' => 'secret123']],
            ['postJson', '/admin/user/account/edit', ['id' => $user->id, 'nickname' => 'Changed']],
            ['postJson', '/admin/user/account/delete', ['id' => $user->id]],
            ['getJson', '/admin/user/account/recycle', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->{$method}($uri, $payload);

            $response->assertOk()
                ->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/account/export')->assertForbidden();

        $this->assertDatabaseHas('user_account', [
            'id' => $user->id,
            'nickname' => 'Read Only User',
            'status' => 'active',
            'delete_time' => null,
        ]);
        $this->assertDatabaseMissing('user_account', [
            'mobile' => '13800138011',
        ]);
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
