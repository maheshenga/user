<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleCenterControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();
        Config::set('modules.path', base_path('tests/Fixtures/modules'));
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
    }

    public function test_index_ajax_returns_module_rows(): void
    {
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'installed',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'installed_at' => time(),
        ]);

        $response = $this->getJson('/admin/system/module/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.name', 'blog')
            ->assertJsonPath('data.0.title', 'Blog Module')
            ->assertJsonPath('data.0.status', 'installed');
    }

    public function test_module_menu_sync_creates_system_module_management_entry(): void
    {
        $this->artisan('system:module-menu:sync')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $parent = \DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', '系统管理')
            ->where('href', '')
            ->whereNull('delete_time')
            ->first();

        $this->assertNotNull($parent);
        $this->assertDatabaseHas('system_menu', [
            'pid' => $parent->id,
            'title' => '模块管理',
            'href' => 'system/module/index',
            'status' => 1,
            'delete_time' => null,
        ]);
    }

    public function test_admin_smoke_script_checks_module_center_visibility_and_actions(): void
    {
        $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

        $this->assertIsString($script);
        $this->assertStringContainsString('expectModuleCenterMenu', $script);
        $this->assertStringContainsString('expectModuleCenterPage', $script);
        $this->assertStringContainsString('expectModuleCenterScript', $script);
        $this->assertStringContainsString('system/module/index', $script);
        $this->assertStringContainsString('/static/admin/js/system/module.js', $script);
        $this->assertStringContainsString('data-module-action', $script);
        $this->assertStringContainsString('data-module-reject', $script);
        $this->assertStringContainsString('approve_url', $script);
        $this->assertStringContainsString('rollback_url', $script);
    }

    public function test_detail_renders_module_metadata(): void
    {
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'installed_at' => time(),
        ]);

        $response = $this->get('/admin/system/module/detail?name=blog');

        $response->assertOk()
            ->assertSee('Blog Module')
            ->assertSee('easyadmin8')
            ->assertSee('Modules\\Blog')
            ->assertSee('blog/post/index');
    }

    public function test_logs_ajax_returns_module_log_rows(): void
    {
        SystemModuleLog::query()->create([
            'admin_id' => 1,
            'module' => 'blog',
            'action' => 'install',
            'old_state' => null,
            'new_state' => 'installed',
            'started_at' => time(),
            'finished_at' => time(),
            'result' => 'success',
            'error_message' => null,
        ]);

        $response = $this->getJson('/admin/system/module/logs?module=blog');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.module', 'blog')
            ->assertJsonPath('data.0.action', 'install')
            ->assertJsonPath('data.0.result', 'success');
    }

    public function test_get_lifecycle_action_is_rejected_without_changing_database(): void
    {
        $this->createBlogModule(['status' => 'enabled']);

        $response = $this->getJson('/admin/system/module/disable?name=blog');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '模块生命周期操作必须使用 POST 请求。');

        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'status' => 'enabled',
        ]);
        $this->assertDatabaseMissing('system_module_log', [
            'module' => 'blog',
            'action' => 'disable',
        ]);
    }

    public function test_post_lifecycle_action_still_delegates_to_service(): void
    {
        $response = $this->postJson('/admin/system/module/disable', ['name' => 'missing']);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '模块未安装：missing');
    }

    public function test_admin_can_approve_pending_module(): void
    {
        $this->createBlogModule(['status' => 'pending_review']);

        $response = $this->postJson('/admin/system/module/approve', ['name' => 'blog']);

        $response->assertOk()->assertJsonPath('code', 1);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'approved']);
        $this->assertDatabaseHas('system_module_log', [
            'module' => 'blog',
            'action' => 'approve',
            'result' => 'success',
        ]);
    }

    public function test_admin_can_reject_pending_module_with_reason(): void
    {
        $this->createBlogModule(['status' => 'pending_review']);

        $response = $this->postJson('/admin/system/module/reject', [
            'name' => 'blog',
            'reason' => 'manual review failed',
        ]);

        $response->assertOk()->assertJsonPath('code', 1);
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'status' => 'rejected',
            'last_error' => 'manual review failed',
        ]);
        $this->assertDatabaseHas('system_module_log', [
            'module' => 'blog',
            'action' => 'reject',
            'error_message' => 'manual review failed',
        ]);
    }

    public function test_review_actions_reject_get_requests(): void
    {
        $this->createBlogModule(['status' => 'pending_review']);

        $response = $this->getJson('/admin/system/module/approve?name=blog');

        $response->assertOk()->assertJsonPath('msg', '模块生命周期操作必须使用 POST 请求。');
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'pending_review']);
    }

    public function test_upgrade_zip_rejects_non_zip_upload(): void
    {
        $response = $this->postJson('/admin/system/module/upgradeZip', [
            'file' => UploadedFile::fake()->create('module.txt', 1, 'text/plain'),
            'name' => 'blog',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['code', 'msg', 'data', 'url', 'wait', '__token__']);
    }

    private function createSystemConfigTable(): void
    {
        Schema::create('system_config', function (Blueprint $table) {
            $table->id();
            $table->string('group', 80);
            $table->string('name', 120);
            $table->text('value')->nullable();
        });

        \DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => 'testing'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin Test'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'wangEditor'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createBlogModule(array $overrides = []): SystemModule
    {
        return SystemModule::query()->create(array_merge([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'installed',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'installed_at' => time(),
        ], $overrides));
    }
}
