<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModuleVersion;
use App\Modules\ModuleUpgrader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        $this->deletePath(storage_path('modules/locks'));

        parent::tearDown();
    }

    public function test_local_upgrade_rejects_same_or_lower_version(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->installApprovedModule('blog');

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
            $this->assertStringContainsString('必须大于当前版本 [1.0.0]', $exception->getMessage());
        }
    }

    public function test_local_upgrade_updates_version_and_records_history(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));

        app(ModuleUpgrader::class)->upgradeLocal('blog', 7);

        $backups = $this->moduleBackupDirectories('blog');
        $this->assertCount(1, $backups);
        $this->assertFileExists($backups[0].DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileExists($backups[0].DIRECTORY_SEPARATOR.'module.json');
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

    public function test_local_upgrade_rejects_manifest_name_mismatch(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->installApprovedModule('blog');

        $this->writeModule('Blog', $this->manifest('shop', '1.1.0'));

        try {
            app(ModuleUpgrader::class)->upgradeLocal('blog');
            $this->fail('Expected local upgrade to reject manifest name mismatch.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('期望模块 [blog]，实际为 [shop]。', $exception->getMessage());
        }

        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
        $this->assertDatabaseMissing('system_module_version', ['module' => 'shop', 'version' => '1.1.0']);
        $this->assertDatabaseMissing('system_module_log', [
            'module' => 'shop',
            'action' => 'upgrade',
            'result' => 'success',
        ]);
    }

    public function test_local_upgrade_rejects_busy_module_lock(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->installApprovedModule('blog');
        $this->writeModule('Blog', $this->manifest('blog', '1.1.0'));

        $lockDir = storage_path('modules/locks');
        mkdir($lockDir, 0777, true);
        $lock = fopen($lockDir.DIRECTORY_SEPARATOR.'blog.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            app(ModuleUpgrader::class)->upgradeLocal('blog');
            $this->fail('Expected local upgrade to reject a busy module lock.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('模块 [blog] 正在升级中，请稍后再试。', $exception->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
    }

    public function test_zip_upgrade_replaces_installed_module_and_records_version_and_log(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');

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

    public function test_zip_upgrade_rejects_expected_name_mismatch_and_cleans_temp_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.0.0'),
        ]);
        $before = $this->moduleTmpDirectories();

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'shop');
            $this->fail('Expected zip upgrade to reject expected name mismatch.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('期望模块 [shop]，实际为 [blog]。', $exception->getMessage());
        }

        $this->assertSame($before, $this->moduleTmpDirectories());
    }

    public function test_zip_upgrade_restores_files_when_validation_fails_after_replace(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
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
            $this->assertStringContainsString('保留的后台前缀 [admin]', $exception->getMessage());
        }

        $restoredManifest = json_decode(file_get_contents($modulePath.DIRECTORY_SEPARATOR.'module.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $restoredManifest['version']);
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.0.0',
            'last_error' => '模块 [blog] 不能使用保留的后台前缀 [admin]，该前缀已被内置后台路由占用。',
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
            $this->assertStringContainsString('保留的后台前缀 [admin]', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'Blog');
        $this->assertDatabaseMissing('system_module', ['name' => 'blog']);
    }

    public function test_zip_install_rejects_existing_target_directory_without_overwrite(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $target = $this->root.DIRECTORY_SEPARATOR.'Blog';
        mkdir($target, 0777, true);
        file_put_contents($target.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-install-existing.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.0.0'),
            'Blog/new.txt' => 'new',
        ]);

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected zip install to reject existing target directory.');
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            $this->assertStringContainsString('模块目标目录已存在', $exception->getMessage());
        }

        $this->assertFileExists($target.DIRECTORY_SEPARATOR.'keep.txt');
        $this->assertFileDoesNotExist($target.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertDatabaseMissing('system_module', ['name' => 'blog']);
    }

    public function test_zip_upgrade_restores_files_and_database_when_migration_fails(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-migration-fails.zip';
        $migration = <<<'PHP'
<?php

return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('CREATE TABLE module_upgrade_cleanup_probe (id integer primary key)');
        throw new RuntimeException('boom');
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP TABLE IF EXISTS module_upgrade_cleanup_probe');
    }
};
PHP;
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.1.0'),
            'Blog/new.txt' => 'new',
            'Blog/database/migrations/2026_07_04_000001_boom.php' => $migration,
        ]);

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected zip upgrade migration to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('module_upgrade_cleanup_probe'));
        $restoredManifest = json_decode(file_get_contents($modulePath.DIRECTORY_SEPARATOR.'module.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $restoredManifest['version']);
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
        $this->assertDatabaseMissing('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000001_boom.php',
        ]);
        $this->assertSame(1, DB::table('system_module')->where('name', 'blog')->count());
    }

    public function test_zip_upgrade_keeps_unique_failure_inside_migration_fatal(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-unique-migration-fails.zip';
        $migration = <<<'PHP'
<?php

return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement('CREATE TABLE module_unique_probe (id integer primary key, value varchar(10) not null unique)');
        \Illuminate\Support\Facades\DB::table('module_unique_probe')->insert(['value' => 'same']);
        \Illuminate\Support\Facades\DB::table('module_unique_probe')->insert(['value' => 'same']);
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP TABLE IF EXISTS module_unique_probe');
    }
};
PHP;
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.1.0'),
            'Blog/new.txt' => 'new',
            'Blog/database/migrations/2026_07_04_000002_unique_fails.php' => $migration,
        ]);

        try {
            app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
            $this->fail('Expected zip upgrade migration unique constraint to fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('module_unique_probe', $exception->getMessage());
        }

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('module_unique_probe'));
        $restoredManifest = json_decode(file_get_contents($modulePath.DIRECTORY_SEPARATOR.'module.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $restoredManifest['version']);
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
        $this->assertDatabaseMissing('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_unique_fails.php',
        ]);
    }

    public function test_zip_install_stages_flat_zip_for_admin_review_and_cleans_temp_directory(): void
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
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.0.0',
            'status' => 'pending_review',
        ]);
    }

    public function test_zip_install_runs_module_migrations(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-install-migration.zip';
        $migration = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('zip_install_items', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('zip_install_items'); }
};
PHP;
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->manifest('blog', '1.0.0'),
            'Blog/database/migrations/2026_07_04_000001_create_zip_install_items.php' => $migration,
        ]);

        app(ModuleUpgrader::class)->upgradeZip($zipPath, 'blog');
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('zip_install_items'));

        $this->installApprovedModule('blog');

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('zip_install_items'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000001_create_zip_install_items.php',
            'batch' => 1,
        ]);
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

    /**
     * @return array<int, string>
     */
    private function moduleBackupDirectories(string $module): array
    {
        $root = storage_path('modules/backups/'.$module);
        if (! is_dir($root)) {
            return [];
        }

        $dirs = array_values(array_map(
            static fn (string $entry): string => $root.DIRECTORY_SEPARATOR.$entry,
            array_filter(
                scandir($root) ?: [],
                static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
            )
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
