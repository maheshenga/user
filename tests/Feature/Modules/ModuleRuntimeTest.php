<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleRuntimeTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        parent::setUp();
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 120);
                $table->string('name', 120);
                $table->text('value')->nullable();
            });
        }
        DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => 'test-version'],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'wangEditor'],
            ['group' => 'site', 'name' => 'iframe_open_top', 'value' => '0'],
        ]);
        Config::set('modules.path', base_path('tests/Fixtures/modules'));
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
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
            'enabled_at' => time(),
        ]);
        SystemModule::query()->create([
            'name' => 'runtime_only',
            'title' => 'Runtime Only Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/RuntimeOnly'),
            'namespace' => 'Modules\\RuntimeOnly',
            'admin_prefix' => 'runtime',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/RuntimeOnly/module.json')), true),
            'enabled_at' => time(),
        ]);
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
    }

    public function test_enabled_module_admin_route_renders_module_view(): void
    {
        $response = $this->get('/admin/blog/post/index');

        $response->assertOk();
        $response->assertSee('module-blog-post-index');
        $response->assertSee('CONTROLLER_JS_PATH: "/module-assets/blog/js/post.js"', false);
    }

    public function test_enabled_module_nested_admin_route_renders_nested_module_view(): void
    {
        $response = $this->get('/admin/blog/reports/post/index');

        $response->assertOk();
        $response->assertSee('module-blog-reports-post-index');
    }

    public function test_enabled_module_asset_is_served(): void
    {
        $response = $this->get('/module-assets/blog/js/post.js');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/javascript; charset=UTF-8');
        $response->assertSee('module-blog-post');
    }

    public function test_admin_loader_keeps_absolute_module_paths_and_legacy_relative_branch(): void
    {
        $configAdmin = file_get_contents(public_path('static/config-admin.js'));

        $this->assertIsString($configAdmin);
        $this->assertStringContainsString("CONFIG.CONTROLLER_JS_PATH.startsWith('/')", $configAdmin);
        $this->assertStringContainsString('controllerJsPath = CONFIG.CONTROLLER_JS_PATH;', $configAdmin);
        $this->assertStringContainsString('controllerJsPath = BASE_URL + CONFIG.CONTROLLER_JS_PATH;', $configAdmin);
    }

    public function test_module_asset_rejects_symlink_escape_when_supported(): void
    {
        $assetRoot = base_path('tests/Fixtures/modules/Blog/assets');
        $outsideDir = storage_path('framework/testing/module-runtime');
        $outsideFile = $outsideDir.DIRECTORY_SEPARATOR.'escaped.js';
        $linkPath = $assetRoot.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'escaped.js';

        if (! is_dir($outsideDir)) {
            mkdir($outsideDir, 0777, true);
        }

        file_put_contents($outsideFile, 'outside-root');

        if (file_exists($linkPath) || is_link($linkPath)) {
            @unlink($linkPath);
        }

        if (! @symlink($outsideFile, $linkPath)) {
            @unlink($outsideFile);
            $this->markTestSkipped('symlink() is unavailable in this environment');
        }

        try {
            $response = $this->get('/module-assets/blog/js/escaped.js');

            $response->assertNotFound();
        } finally {
            @unlink($linkPath);
            @unlink($outsideFile);
        }
    }

    public function test_runtime_only_module_route_is_loaded_without_fixture_autoload_mapping(): void
    {
        $response = $this->get('/admin/runtime/report/index');

        $response->assertOk();
        $response->assertSeeText('runtime-only-index');
    }

    public function test_current_admin_action_uses_module_controller_for_enabled_module(): void
    {
        $response = $this->get('/admin/runtime/report/actionName');

        $response->assertOk();
        $response->assertSeeText('Modules\\RuntimeOnly\\Controllers\\ReportController@actionName');
    }

    public function test_three_segment_module_route_is_not_stolen_by_nested_route_matcher(): void
    {
        $response = $this->get('/admin/runtime/report/index');

        $response->assertOk();
        $response->assertSeeText('runtime-only-index');
    }

    public function test_check_login_can_reflect_module_method_annotations(): void
    {
        $this->flushSession();

        $response = $this->get('/admin/runtime/report/index');

        $response->assertOk();
        $response->assertSeeText('runtime-only-index');
    }

    public function test_module_controller_nodes_are_scanned(): void
    {
        $nodes = app(\App\Http\Services\NodeService::class)->getNodeList();
        $nodeNames = array_column($nodes, 'node');

        $this->assertContains('blog/post', $nodeNames);
        $this->assertContains('blog/post/index', $nodeNames);
        $this->assertContains('blog/post/inheritedAction', $nodeNames);
        $this->assertNotContains('blog/post/hiddenInheritedAction', $nodeNames);
        $this->assertContains('blog/reports/post', $nodeNames);
        $this->assertContains('blog/reports/post/index', $nodeNames);
        $this->assertContains('blog/reports/post/inheritedAction', $nodeNames);
    }

    public function test_reserved_prefix_enabled_row_falls_back_to_legacy_resolution(): void
    {
        SystemModule::query()->create([
            'name' => 'dirty_mall',
            'title' => 'Dirty Mall Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'mall',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'enabled_at' => time(),
        ]);

        [$class, $action] = app(\App\Modules\ModuleRouteResolver::class)->resolve('mall', 'post', 'index');

        $this->assertSame('App\\Http\\Controllers\\admin\\mall\\PostController', $class);
        $this->assertSame('index', $action);
    }

    public function test_non_reserved_prefix_still_resolves_to_module_controller(): void
    {
        [$class, $action] = app(\App\Modules\ModuleRouteResolver::class)->resolve('blog', 'post', 'index');

        $this->assertSame('Modules\\Blog\\Controllers\\PostController', $class);
        $this->assertSame('index', $action);
    }
}
