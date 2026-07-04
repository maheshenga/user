<?php

namespace Tests\Feature\Modules;

use App\Modules\ModuleFileStore;
use App\Modules\ModuleRollbacker;
use App\Models\SystemModuleMigration;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\Cache;
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
        $this->installApprovedModule('blog');
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

    public function test_rollback_restores_version_history_metadata_before_backup_manifest_metadata(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->installApprovedModule('blog');

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.1.0');
        SystemModuleVersion::query()->create([
            'module' => 'blog',
            'version' => '1.1.0',
            'manifest_json' => json_decode($this->manifest('blog', '1.1.0', 'History Title'), true, 512, JSON_THROW_ON_ERROR),
            'installed_at' => time(),
            'create_time' => time(),
        ]);

        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.2.0'));
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.2.0',
            'config_json' => json_decode($this->manifest('blog', '1.2.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        app(ModuleRollbacker::class)->rollback('blog');

        $restored = \App\Models\SystemModule::query()->where('name', 'blog')->firstOrFail();
        $this->assertSame('History Title', $restored->title);
        $this->assertSame('1.1.0', $restored->version);
        $this->assertSame(str_replace('\\', '/', $modulePath), str_replace('\\', '/', (string) $restored->path));
    }

    public function test_rollback_rejects_non_installed_statuses_without_changing_files_or_database(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
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
        $this->installApprovedModule('blog');

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
        $this->installApprovedModule('blog');
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
        $this->installApprovedModule('blog');
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
        $this->installApprovedModule('blog');
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

    public function test_rollback_rejects_multiple_missing_migrations_before_files_or_database_change(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        unlink($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        $this->writeMigration($modulePath, '2026_07_04_000002_create_first_added_table.php', 'first_added_manual_rollback');
        $this->writeMigration($modulePath, '2026_07_04_000003_create_second_added_table.php', 'second_added_manual_rollback');
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000002_create_first_added_table.php', 2);
        $this->runAndRecordMigration($modulePath, 'blog', '2026_07_04_000003_create_second_added_table.php', 2);
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback to require manual migration rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Manual rollback required', $exception->getMessage());
        }

        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'current.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertTrue(Schema::hasTable('first_added_manual_rollback'));
        $this->assertTrue(Schema::hasTable('second_added_manual_rollback'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_create_first_added_table.php',
        ]);
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000003_create_second_added_table.php',
        ]);
    }

    public function test_rollback_keeps_missing_migration_record_when_down_fails(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        unlink($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        $this->writeThrowingDownMigration($modulePath, '2026_07_04_000002_throwing_down.php');
        SystemModuleMigration::query()->create([
            'module' => 'blog',
            'migration' => '2026_07_04_000002_throwing_down.php',
            'batch' => 2,
            'ran_at' => time(),
        ]);
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback migration down to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('intentional down failure', $exception->getMessage());
        }

        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'current.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_throwing_down.php',
        ]);
    }

    public function test_rollback_blocks_when_recorded_migration_missing_from_backup_has_no_current_file(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        $this->installApprovedModule('blog');
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
        $this->installApprovedModule('blog');
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

    public function test_rollback_chooses_newest_backup_timestamp_before_directory_mtime(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.11.0'));
        $this->installApprovedModule('blog');

        $root = storage_path('modules/backups/blog');
        mkdir($root, 0777, true);
        $older = $root.DIRECTORY_SEPARATOR.'20260704000000-1.9.0-zzz';
        $newer = $root.DIRECTORY_SEPARATOR.'20260704000001-1.10.0-aaa';
        mkdir($older, 0777, true);
        mkdir($newer, 0777, true);
        file_put_contents($older.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.9.0'));
        file_put_contents($older.DIRECTORY_SEPARATOR.'chosen.txt', 'older');
        file_put_contents($newer.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.10.0'));
        file_put_contents($newer.DIRECTORY_SEPARATOR.'chosen.txt', 'newer');
        touch($older, time() + 10);
        touch($newer, time());

        app(ModuleRollbacker::class)->rollback('blog');

        $this->assertSame('newer', file_get_contents($modulePath.DIRECTORY_SEPARATOR.'chosen.txt'));
        $this->assertDatabaseHas('system_module', [
            'name' => 'blog',
            'version' => '1.10.0',
        ]);
    }

    public function test_rollback_runs_missing_migration_down_before_replacing_files(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
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

        app(ModuleRollbacker::class)->rollback('blog');

        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'current.txt');
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertDatabaseMissing('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_04_000002_restore_order.php',
        ]);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
    }

    public function test_rollback_copy_preflight_failure_does_not_rollback_migration(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink unavailable.');
        }

        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        $this->installApprovedModule('blog');
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

    public function test_rollback_keeps_current_temp_when_post_replace_cache_clear_fails(): void
    {
        $modulePath = $this->writeModule('Blog', $this->manifest('blog', '1.0.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'old.txt', 'old');
        $this->installApprovedModule('blog');
        app(ModuleFileStore::class)->backup($modulePath, 'blog', '1.0.0');

        unlink($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', '1.1.0'));
        file_put_contents($modulePath.DIRECTORY_SEPARATOR.'current.txt', 'current');
        \App\Models\SystemModule::query()->where('name', 'blog')->update([
            'version' => '1.1.0',
            'config_json' => json_decode($this->manifest('blog', '1.1.0'), true, 512, JSON_THROW_ON_ERROR),
        ]);

        Cache::shouldReceive('forget')
            ->once()
            ->with(config('modules.cache_key'))
            ->andThrow(new RuntimeException('cache clear failed'));

        try {
            app(ModuleRollbacker::class)->rollback('blog');
            $this->fail('Expected rollback cache clear to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cache clear failed', $exception->getMessage());
        }

        $currentTemps = glob(storage_path('modules/tmp/rollback_current_*')) ?: [];
        $this->assertCount(1, $currentTemps);
        $this->assertFileExists($currentTemps[0].DIRECTORY_SEPARATOR.'current.txt');
        $this->assertSame('current', file_get_contents($currentTemps[0].DIRECTORY_SEPARATOR.'current.txt'));
        $this->assertFileExists($modulePath.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileDoesNotExist($modulePath.DIRECTORY_SEPARATOR.'current.txt');
    }

    /**
     * @throws JsonException
     */
    private function manifest(string $name, string $version, ?string $title = null): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => $title ?? ucfirst($name).' Module',
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

    private function writeThrowingDownMigration(string $modulePath, string $filename): void
    {
        $path = $modulePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.DIRECTORY_SEPARATOR.$filename, <<<'PHP'
<?php
return new class {
    public function down(): void
    {
        throw new RuntimeException('intentional down failure');
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
