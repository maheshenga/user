<?php

namespace Tests\Unit\Modules;

use App\Modules\ModuleManifest;
use InvalidArgumentException;
use JsonException;
use Tests\TestCase;

class ModuleManifestTest extends TestCase
{
    public function test_manifest_is_loaded_and_normalized(): void
    {
        $manifest = ModuleManifest::fromFile(base_path('tests/Fixtures/modules/Blog/module.json'));

        $this->assertSame('blog', $manifest->name());
        $this->assertSame('Blog Module', $manifest->title());
        $this->assertSame('Modules\\Blog', $manifest->namespace());
        $this->assertSame('blog', $manifest->adminPrefix());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/src/Controllers', $manifest->controllersPath());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/resources/views', $manifest->viewsPath());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/assets', $manifest->assetsPath());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/database/migrations', (string) $manifest->migrationsPath());
        $this->assertSame('blog/post/index', $manifest->menus()[0]['children'][0]['href']);
    }

    public function test_manifest_rejects_missing_required_fields(): void
    {
        $path = base_path('storage/framework/testing-invalid-module.json');
        file_put_contents($path, json_encode(['name' => 'broken'], JSON_THROW_ON_ERROR));

        try {
            try {
                ModuleManifest::fromFile($path);
                $this->fail('Expected invalid manifest to throw.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('module.json missing required field: schema_version', $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }

        $this->assertFileDoesNotExist($path);
    }

    /**
     * @throws JsonException
     */
    public function test_manifest_rejects_module_local_paths_that_escape_module_root(): void
    {
        $path = base_path('storage/framework/testing-invalid-module-path.json');
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'controllers' => './src/Controllers/../Controllers',
            'views' => 'resources/views/./partials/..',
            'assets' => '../module-assets',
        ], JSON_THROW_ON_ERROR));

        try {
            try {
                ModuleManifest::fromFile($path);
                $this->fail('Expected manifest path escape to throw.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('module.json path escapes module root: assets', $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_manifest_rejects_entry_paths_that_escape_module_root(): void
    {
        $path = base_path('storage/framework/testing-invalid-module-entry-path.json');
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'entry' => '../outside/BlogServiceProvider.php',
        ], JSON_THROW_ON_ERROR));

        try {
            try {
                ModuleManifest::fromFile($path);
                $this->fail('Expected manifest entry path escape to throw.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('module.json path escapes module root: entry', $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_manifest_normalizes_safe_relative_paths_without_requiring_existing_directories(): void
    {
        $path = base_path('storage/framework/testing-normalized-module.json');
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'controllers' => './src/Controllers/../Controllers',
            'views' => 'resources/views/./partials/..',
            'assets' => './assets/./compiled/..',
        ], JSON_THROW_ON_ERROR));

        try {
            $manifest = ModuleManifest::fromFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            str_replace('\\', '/', base_path('storage/framework/src/Controllers')),
            $manifest->controllersPath()
        );
        $this->assertSame(
            str_replace('\\', '/', base_path('storage/framework/resources/views')),
            $manifest->toArray()['views']
        );
        $this->assertSame(
            str_replace('\\', '/', base_path('storage/framework/assets')),
            $manifest->toArray()['assets']
        );
    }
}
