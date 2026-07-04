<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
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
            CheckAuth::class,
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
