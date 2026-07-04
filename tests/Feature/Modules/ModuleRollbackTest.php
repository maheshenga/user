<?php

namespace Tests\Feature\Modules;

use App\Modules\ModuleFileStore;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRollbacker;
use App\Models\SystemModuleMigration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleRollbackTest extends TestCase
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

        $this->root = storage_path('framework/testing-module-rollbacks');
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

    public function test_rollback_restores_latest_backup_and_previous_version_metadata(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        app(ModuleInstaller::class)->install('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'new.txt', 'new');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.1.0');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.2.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.2.0',
            'config_json' => json_decode($this->manifest('blog', '1.2.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        app(ModuleRollbacker::class)->rollback('blog', 5);

        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'current.txt');
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.1.0',
            'status' => 'installed',
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('system_module_log', [
            'admin_id' => 5,
            'module' => 'blog',
            'action' => 'rollback',
            'result' => 'success',
        ]);
    }

    public function test_rollback_rejects_non_installed_statuses_without_changing_files_or_database(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        app(ModuleInstaller::class)->install('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');

        foreach (['discovered', 'uninstalled', 'failed'] as $status) {
            \App\Models\SystemModule::query()->where('name', 'blog')->update([
                'status' => $status,
                'version' => '1.1.0',
                'last_error' => null,
            ]);
            DB::table('system_module_log')->delete();

            try {
                app(ModuleRollbacker::class)->rollback('blog');
                $this->fail("Expected rollback to reject {$status} status.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString("cannot be rolled back from status [{$status}]", $exception->getMessage());
            }

            $this->assertSame('current', file_get_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt'));
            $this->assertDatabaseHas('system_module', [
                'name' => 'blog',
                'version' => '1.1.0',
                'status' => $status,
                'last_error' => null,
            ]);
            $this->assertSame(0, DB::table('system_module_log')->count());
        }
    }

    public function test_rollback_without_backup_sets_last_error_and_logs_failure(): void
    {
        $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        app(ModuleInstaller::class)->install('blog');

        try {
            app(ModuleRollbacker::class)->rollback('blog', 6);
            $this->fail('Expected rollback to require a backup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No backup found', $exception->getMessage());
        }

        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.0.0',
            'last_error' => 'No backup found for module: blog',
        ]);
        $this->assertDatabaseHas('system_module_log', [
            'admin_id' => 6,
            'module' => 'blog',
            'action' => 'rollback',
            'result' => 'failed',
        ]);
    }

    public function test_rollback_rejects_backup_manifest_name_mismatch_without_changing_files_or_database(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'keep.txt', 'current');
        app(ModuleInstaller::class)->install('blog');
        $backup = app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');
        file_put_contents($backup.DIRECTORY_SEPARATOR.'module.json', $this->manifest('shop', '1.0.0'));

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback to reject a mismatched backup manifest.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Expected module [blog], got [shop].', $exception->getMessage());
        }

        $this->assertSame('current', file_get_contents($modulePath.DIRECTORY_SEPARATOR.'keep.txt'));
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.0.0',
        ]);
    }

    public function test_rollback_keeps_recorded_migration_that_exists_in_current_and_backup_manifests(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->writeMigration($modulePath, '2026_07_04_000001_create_shared_table.php', 'shared_rollback_keep');
        app(ModuleInstaller::class)->install('blog');
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000001_create_shared_table.php');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        app(ModuleRollbacker::class)->rollback('blog');

        $this->assertTrue(Schema::hasTable('shared_rollback_keep'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000001_create_shared_table.php',
        ]);
    }

    public function test_rollback_removes_recorded_migration_missing_from_backup_manifest(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->writeMigration($modulePath, '2026_07_04_000001_create_shared_table.php', 'shared_rollback_remove');
        app(ModuleInstaller::class)->install('blog');
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000001_create_shared_table.php');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        $this->writeMigration($modulePath, '2026_07_04_000002_create_added_table.php', 'added_rollback_remove');
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000002_create_added_table.php', 2);
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        app(ModuleRollbacker::class)->rollback('blog');

        $this->assertTrue(Schema::hasTable('shared_rollback_remove'));
        $this->assertFalse(Schema::hasTable('added_rollback_remove'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000001_create_shared_table.php',
        ]);
        $this->assertDatabaseMissing('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_create_added_table.php',
        ]);
    }

    public function test_rollback_blocks_when_recorded_migration_missing_from_backup_has_no_current_file(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        app(ModuleInstaller::class)->install('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');
        SystemModuleMigration::query()->create([
            'module' => 'blog',
            'migration' => '2026_07_04_000002_missing_current_file.php',
            'batch' => 2,
            'ran_at' => time(),
        ]);

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback to require the current migration file.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Recorded module migration file is missing: 2026_07_04_000002_missing_current_file.php', $exception->getMessage());
        }

        $this->assertSame('current', file_get_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_missing_current_file.php',
        ]);
    }

    public function test_rollback_rejects_busy_module_lock(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        app(ModuleInstaller::class)->install('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        $lockDir = storage_path('modules/locks');
        mkdir($lockDir, 0777, true);
        $lock = fopen($lockDir.DIRECTORY_SEPARATOR.'blog.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback to reject a busy module lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already upgrading', $exception->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame('current', file_get_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt'));
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
    }

    public function test_rollback_restores_current_files_when_migration_down_fails_after_file_replace(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        app(ModuleInstaller::class)->install('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        unlink($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        $this->writeFailingDownMigration($modulePath, '2026_07_04_000002_restore_order.php');
        SystemModuleMigration::query()->create([
            'module' => 'blog',
            'migration' => '2026_07_04_000002_restore_order.php',
            'batch' => 2,
            'ran_at' => time(),
        ]);
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback migration down to fail after file replace.');
        } catch (RuntimeException $exception) {
            $this->assertSame('down saw restored files', $exception->getMessage());
        }

        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'current.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_restore_order.php',
        ]);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.1.0']);
    }

    public function test_rollback_copy_preflight_failure_does_not_rollback_migration(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink unavailable.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        app(ModuleInstaller::class)->install('blog');
        $backup = app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        $this->writeMigration($modulePath, '2026_07_04_000002_create_added_table.php', 'added_preflight_keep');
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000002_create_added_table.php');
        symlink($backup.DIRECTORY_SEPARATOR.'module.json', $backup.DIRECTORY_SEPARATOR.'bad-link');

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback preflight to reject symlink in backup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to copy symlink', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('added_preflight_keep'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_create_added_table.php',
        ]);
    }

    /**
     * @throws JsonException
     */
    private function manifest(string $name, string $version): string
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
            'admin_prefix' => $name,
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

    private function writeMigration(string $modulePath, string $filename, string $table): void
    {
        $path = $modulePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.DIRECTORY_SEPARATOR.$filename, <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('{$table}', fn (Blueprint \$table) => \$table->id()); }
    public function down(): void { Schema::dropIfExists('{$table}'); }
};
PHP);
    }

    private function writeFailingDownMigration(string $modulePath, string $filename): void
    {
        $path = $modulePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $probe = addslashes($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        file_put_contents($path.DIRECTORY_SEPARATOR.$filename, <<<PHP
<?php
return new class {
    public function down(): void
    {
        if (file_exists('{$probe}')) {
            throw new RuntimeException('down saw restored files');
        }
    }
};
PHP);
    }

    private function runAndRecordMigration(string $modulePath, string $module, string $filename, int $batch = 1): void
    {
        $migration = require $modulePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.$filename;
        $migration->up();
        SystemModuleMigration::query()->create([
            'module' => $module,
            'migration' => $filename,
            'batch' => $batch,
            'ran_at' => time(),
        ]);
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
