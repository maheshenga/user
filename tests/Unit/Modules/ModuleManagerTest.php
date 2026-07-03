<?php

namespace Tests\Unit\Modules;

use App\Models\SystemModule;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Config;
use JsonException;
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

    public function test_discovery_skips_invalid_manifests_beside_valid_modules(): void
    {
        $root = base_path('storage/framework/testing-modules-discovery');
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'Blog', $this->blogManifest());
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'Broken', '{');
        Config::set('modules.path', $root);

        try {
            $modules = app(ModuleManager::class)->discover();
        } finally {
            $this->deleteDirectory($root);
        }

        $this->assertSame(['blog'], array_keys($modules));
        $this->assertSame('blog', $modules['blog']->name());
    }

    public function test_enabled_returns_empty_before_system_module_table_exists(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $modules = app(ModuleManager::class)->enabled();

        $this->assertSame([], $modules);
    }

    public function test_enabled_by_prefix_returns_null_before_system_module_table_exists(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $manifest = app(ModuleManager::class)->enabledByPrefix('blog');

        $this->assertNull($manifest);
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

    public function test_enabled_skips_stale_or_invalid_rows(): void
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

        SystemModule::query()->create([
            'name' => 'stale',
            'title' => 'Stale Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Stale'),
            'namespace' => 'Modules\\Stale',
            'admin_prefix' => 'stale',
            'enabled_at' => time(),
        ]);

        $modules = app(ModuleManager::class)->enabled();

        $this->assertSame(['blog'], array_keys($modules));
    }

    public function test_enabled_by_prefix_skips_bad_enabled_row(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $root = base_path('storage/framework/testing-modules-enabled');
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'Blog', $this->blogManifest());
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'Broken', '{');
        Config::set('modules.path', $root);

        SystemModule::query()->create([
            'name' => 'broken',
            'title' => 'Broken Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $root.DIRECTORY_SEPARATOR.'Broken',
            'namespace' => 'Modules\\Broken',
            'admin_prefix' => 'broken',
            'enabled_at' => time(),
        ]);

        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $root.DIRECTORY_SEPARATOR.'Blog',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'enabled_at' => time(),
        ]);

        try {
            $broken = app(ModuleManager::class)->enabledByPrefix('broken');
            $good = app(ModuleManager::class)->enabledByPrefix('blog');
        } finally {
            $this->deleteDirectory($root);
        }

        $this->assertNull($broken);
        $this->assertNotNull($good);
        $this->assertSame('blog', $good->name());
    }

    /**
     * @throws JsonException
     */
    private function blogManifest(): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
        ], JSON_THROW_ON_ERROR);
    }

    private function ensureModuleFixture(string $path, string $manifest): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.DIRECTORY_SEPARATOR.'module.json', $manifest);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
