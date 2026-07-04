<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use Illuminate\Database\Schema\Blueprint;
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
}
