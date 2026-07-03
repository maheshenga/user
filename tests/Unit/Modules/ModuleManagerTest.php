<?php

namespace Tests\Unit\Modules;

use App\Models\SystemModule;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleManagerTest extends TestCase
{
    use CreatesModuleTestSchema;

    public function test_discovers_modules_from_configured_path(): void
    {
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $modules = app(ModuleManager::class)->discover();

        $this->assertArrayHasKey('blog', $modules);
        $this->assertSame('blog', $modules['blog']->adminPrefix());
    }

    public function test_enabled_module_can_be_found_by_admin_prefix(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

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
            'enabled_at' => time(),
        ]);

        $manifest = app(ModuleManager::class)->enabledByPrefix('blog');

        $this->assertNotNull($manifest);
        $this->assertSame('blog', $manifest->name());
    }
}
