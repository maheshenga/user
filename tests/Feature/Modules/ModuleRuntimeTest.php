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
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
    }

    public function test_enabled_module_admin_route_renders_module_view(): void
    {
        $response = $this->get('/admin/blog/post/index');

        $response->assertOk();
        $response->assertSee('module-blog-post-index');
    }

    public function test_enabled_module_asset_is_served(): void
    {
        $response = $this->get('/module-assets/blog/js/post.js');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/javascript; charset=UTF-8');
        $response->assertSee('module-blog-post');
    }
}
