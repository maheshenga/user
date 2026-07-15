<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Models\SystemModuleRelease;
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

    public function test_index_ignores_unknown_filter_columns_and_unsafe_sorting(): void
    {
        $this->createBlogModule();

        $response = $this->getJson('/admin/system/module/index?'.http_build_query([
            'filter' => json_encode(['name) OR 1=1 --' => 'blog'], JSON_THROW_ON_ERROR),
            'op' => json_encode(['name) OR 1=1 --' => '='], JSON_THROW_ON_ERROR),
            'tableOrder' => 'name desc, (select 1)',
        ]));

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.name', 'blog');
    }

    public function test_index_normalizes_in_filter_values_without_executing_sql_fragments(): void
    {
        $blog = $this->createBlogModule();
        $this->createBlogModule([
            'name' => 'shop',
            'title' => 'Shop Module',
            'admin_prefix' => 'shop',
        ]);

        $response = $this->getJson('/admin/system/module/index?'.http_build_query([
            'filter' => json_encode(['id' => $blog->id.', 0) OR 1=1 --'], JSON_THROW_ON_ERROR),
            'op' => json_encode(['id' => 'in'], JSON_THROW_ON_ERROR),
        ]));

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.name', 'blog');
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

    public function test_module_menu_sync_reports_missing_system_menu_table_in_chinese(): void
    {
        Schema::dropIfExists('system_menu');

        $this->artisan('system:module-menu:sync')
            ->expectsOutputToContain('系统菜单表不存在，请先完成后台菜单数据表迁移。')
            ->assertExitCode(1);
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
        $this->assertStringContainsString('data-review-detail', $script);
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
        $response->assertViewHas('reviewDetails', function (array $details): bool {
            foreach ($details['manifest_diff'] as $diff) {
                if ($diff['added'] !== [] || $diff['removed'] !== [] || $diff['changed'] !== []) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_detail_contains_complete_release_review_context(): void
    {
        $activeManifest = json_decode(
            file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $pendingManifest = $activeManifest;
        $pendingManifest['version'] = '1.1.0';
        $pendingManifest['permissions'][] = 'balance:read';
        $pendingManifest['api'] = ['abilities' => ['profile:read'], 'quotas' => ['profile.read' => 100]];
        $pendingManifest['external_domains'] = ['api.example.com'];
        $pendingManifest['dependencies'] = ['foundation' => '^1.0'];
        $pendingManifest['conflicts'] = ['legacy_blog' => '*'];

        $module = $this->createBlogModule([
            'status' => 'enabled',
            'config_json' => $activeManifest,
        ]);
        $active = SystemModuleRelease::query()->create([
            'module' => 'blog',
            'version' => '1.0.0',
            'source_type' => 'local',
            'trust_level' => 'private',
            'artifact_path' => base_path('tests/Fixtures/modules/Blog'),
            'artifact_hash' => hash('sha256', 'active'),
            'signature_hash' => hash('sha256', 'active-signature'),
            'manifest_json' => $activeManifest,
            'status' => 'active',
            'previous_status' => 'installed',
            'uploaded_by' => 5,
            'reviewed_by' => 6,
            'reviewed_at' => now()->subDay(),
            'activated_at' => now()->subDay(),
        ]);
        $pending = SystemModuleRelease::query()->create([
            'module' => 'blog',
            'version' => '1.1.0',
            'source_type' => 'zip',
            'trust_level' => 'community',
            'artifact_path' => storage_path('modules/releases/blog/pending'),
            'artifact_hash' => hash('sha256', 'pending'),
            'signature_hash' => null,
            'manifest_json' => $pendingManifest,
            'status' => 'pending_review',
            'previous_status' => 'enabled',
            'uploaded_by' => 7,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_reason' => '<script>alert(1)</script>',
        ]);
        $module->forceFill([
            'active_release_id' => $active->id,
            'pending_release_id' => $pending->id,
        ])->save();

        $response = $this->get('/admin/system/module/detail?name=blog');

        $response->assertOk()->assertViewHas('reviewDetails', function (mixed $details): bool {
            return is_array($details)
                && $details['active']['version'] === '1.0.0'
                && $details['pending']['version'] === '1.1.0'
                && $details['pending']['source_type'] === 'zip'
                && $details['pending']['uploaded_by'] === 7
                && $details['pending']['signature_state'] === 'unsigned'
                && in_array('balance:read', $details['manifest_diff']['permissions']['added'], true)
                && in_array('profile:read', $details['manifest_diff']['api_abilities']['added'], true)
                && in_array('api.example.com', $details['manifest_diff']['external_domains']['added'], true)
                && isset($details['manifest_diff']['dependencies']['added']['foundation'])
                && isset($details['manifest_diff']['conflicts']['added']['legacy_blog'])
                && count($details['release_history']) === 2;
        });
        $response->assertSee('待审制品')
            ->assertSee('权限与依赖差异')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
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
            'action' => 'approve_release',
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
     * @param  array<string, mixed>  $overrides
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
