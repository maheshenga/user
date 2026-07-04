<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModuleVersion;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleUpgrader;
use Illuminate\Support\Facades\Config;
use JsonException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleUpgradeTest extends TestCase
{
    use CreatesModuleTestSchema;

    private string $root;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));

        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->root = storage_path('framework/testing-module-upgrades');
        $this->deletePath($this->root);
        mkdir($this->root, 0777, true);
        Config::set('modules.path', $this->root);
    }

    protected function tearDown(): void
    {
        $this->deletePath($this->root);
        $this->deletePath(storage_path('modules/tmp'));
        $this->deletePath(storage_path('modules/backups'));

        parent::tearDown();
    }

    public function test_local_upgrade_rejects_same_or_lower_version(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        app(ModuleInstaller::class)->install('blog');

        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->assertLocalUpgradeFailsBecauseVersionIsNotGreater();

        $this->writeModule('Blog', $this->manifest('blog', '0.9.0'));
        $this->assertLocalUpgradeFailsBecauseVersionIsNotGreater();
    }

    private function assertLocalUpgradeFailsBecauseVersionIsNotGreater(): void
    {
        try {
            app(ModuleUpgrader::class)->upgradeLocal('blog');
            $this->fail('Expected local upgrade to reject non-increasing versions.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be greater', $exception->getMessage());
        }
    }

    public function test_local_upgrade_updates_version_and_records_history(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        app(ModuleInstaller::class)->install('blog');

        $this->writeModule('Blog', $this->manifest('blog', '1.1.0'));

        app(ModuleUpgrader::class)->upgradeLocal('blog', 7);

        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.1.0',
            'status' => 'installed',
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('system_module_version', ['module' => 'blog', 'version' => '1.1.0']);
        $this->assertSame(2, SystemModuleVersion::query()->where('module', 'blog')->count());
        $this->assertDatabaseHas('system_module_log', [
            'admin_id' => 7,
            'module' => 'blog',
            'action' => 'upgrade',
            'result' => 'success',
        ]);
    }

    public function test_zip_upgrade_replaces_installed_module_and_records_version_and_log(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        app(ModuleInstaller::class)->install('blog');

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.2.0'),
            'Blog/new.txt' => 'new',
        ]);

        app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog', 9);

        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.2.0']);
        $this->assertDatabaseHas('system_module_version', ['module' => 'blog', 'version' => '1.2.0']);
        $this->assertDatabaseHas('system_module_log', [
            'admin_id' => 9,
            'module' => 'blog',
            'action' => 'upgrade',
            'result' => 'success',
        ]);
    }

    public function test_zip_upgrade_restores_files_when_validation_fails_after_replace(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        app(ModuleInstaller::class)->install('blog');
        Config::set('modules.reserved_admin_prefixes', ['admin']);

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-reserved.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.1.0', 'admin'),
            'Blog/new.txt' => 'new',
        ]);

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected zip upgrade to reject reserved admin_prefix.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('reserved admin_prefix [admin]', $exception->getMessage());
        }

        $restoredManifest = json_decode(file_get_contents($modulePath.DIRECTORY_SEPARATOR.'module.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $restoredManifest['version']);
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.0.0',
            'last_error' => 'Module [blog] cannot use reserved admin_prefix [admin] because it is reserved for built-in admin routes.',
        ]);
        $this->assertDatabaseHas('system_module_log', [
            'module' => 'blog',
            'action' => 'upgrade',
            'result' => 'failed',
        ]);
    }

    public function test_zip_install_failure_removes_copied_target_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        Config::set('modules.reserved_admin_prefixes', ['admin']);
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-install-reserved.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.0.0', 'admin'),
            'Blog/new.txt' => 'new',
        ]);

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected zip install to reject reserved admin_prefix.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('reserved admin_prefix [admin]', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'Blog');
        $this->assertDatabaseMissing('system_module', ['name' => 'blog']);
    }

    public function test_zip_install_accepts_flat_zip_and_cleans_temp_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-flat.zip';
        $this->createZip($zipPath, [
            'module.json' => $this->manifest('blog', '1.0.0'),
            'flat.txt' => 'flat',
        ]);
        $before = $this->moduleTmpDirectories();

        app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');

        $this->assertSame($before, $this->moduleTmpDirectories());
        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'Blog'.DIRECTORY_SEPARATOR.'flat.txt');
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
    }

    public function test_bad_manifest_zip_cleans_extracted_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-bad-manifest.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => '{',
        ]);
        $before = $this->moduleTmpDirectories();

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected bad manifest zip to fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Syntax error', $exception->getMessage());
        }

        $this->assertSame($before, $this->moduleTmpDirectories());
    }

    /**
     * @throws JsonException
     */
    private function manifest(string $name, string $version, ?string $adminPrefix = null): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name).' Module',
            'vendor' => 'easyadmin8',
            'version' => $version,
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $adminPrefix ?? $name,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
            'menus' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function writeModule(string $directory, string $manifest): string
    {
        $path = $this->root.DIRECTORY_SEPARATOR.$directory;
        $this->deletePath($path);
        mkdir($path, 0777, true);
        file_put_contents($path.DIRECTORY_SEPARATOR.'module.json', $manifest);

        return $path;
    }

    /**
     * @param array<string, string> $entries
     */
    private function createZip(string $zipPath, array $entries): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($result === true, 'Failed to create zip fixture.');

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    /**
     * @return array<int, string>
     */
    private function moduleTmpDirectories(): array
    {
        $root = storage_path('modules/tmp');
        if (! is_dir($root)) {
            return [];
        }

        $dirs = array_values(array_filter(
            scandir($root) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        sort($dirs);

        return $dirs;
    }

    private function deletePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || @rmdir($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->deletePath($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
