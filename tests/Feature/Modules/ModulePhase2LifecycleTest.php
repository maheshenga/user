<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModuleMigration;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModulePhase2LifecycleTest extends TestCase
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
    }

    public function test_install_records_module_version_snapshot(): void
    {
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        app(\App\Modules\ModuleInstaller::class)->install('blog');

        $this->assertDatabaseHas('system_module_version', [
            'module' => 'blog',
            'version' => '1.0.0',
        ]);

        $snapshot = SystemModuleVersion::query()->where('module', 'blog')->firstOrFail();

        $this->assertSame('blog', $snapshot->manifest_json['name']);
    }

    public function test_migration_runner_runs_only_unrecorded_module_migrations(): void
    {
        $root = storage_path('framework/testing-phase2-migrations');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'migrator',
            'migrator',
            [
                '2026_07_04_000001_create_migrator_table.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('migrator_items', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('migrator_items'); }
};
PHP,
            ]
        );

        try {
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);

            $this->assertTrue(Schema::hasTable('migrator_items'));
            $this->assertSame(1, SystemModuleMigration::query()->where('module', 'migrator')->count());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_migration_runner_records_same_batch_for_all_migrations_in_one_run(): void
    {
        $root = storage_path('framework/testing-phase2-batches');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'batcher',
            'batcher',
            [
                '2026_07_04_000001_create_batcher_one.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('batcher_one', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('batcher_one'); }
};
PHP,
                '2026_07_04_000002_create_batcher_two.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('batcher_two', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('batcher_two'); }
};
PHP,
            ]
        );

        try {
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);

            $batches = SystemModuleMigration::query()
                ->where('module', 'batcher')
                ->orderBy('migration')
                ->pluck('batch')
                ->all();

            $this->assertSame([1, 1], $batches);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_migration_runner_skips_up_when_tracking_row_appears_before_transaction_recheck(): void
    {
        $root = storage_path('framework/testing-phase2-race-recheck');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'racecheck',
            'racecheck',
            [
                '2026_07_04_000001_seed_tracking_row.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::table('system_module_migration')->insert([
            'module' => 'racecheck',
            'migration' => '2026_07_04_000002_create_should_not_run.php',
            'batch' => 1,
            'ran_at' => time(),
        ]);
    }
    public function down(): void {}
};
PHP,
                '2026_07_04_000002_create_should_not_run.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('should_not_run', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('should_not_run'); }
};
PHP,
            ]
        );

        try {
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);

            $this->assertFalse(Schema::hasTable('should_not_run'));
            $this->assertDatabaseHas('system_module_migration', [
                'module' => 'racecheck',
                'migration' => '2026_07_04_000001_seed_tracking_row.php',
            ]);
            $this->assertDatabaseHas('system_module_migration', [
                'module' => 'racecheck',
                'migration' => '2026_07_04_000002_create_should_not_run.php',
            ]);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_rollback_recorded_only_rolls_back_latest_batch(): void
    {
        $root = storage_path('framework/testing-phase2-rollback-latest');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'rollbacker',
            'rollbacker',
            [
                '2026_07_04_000001_create_batch_one.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('rollback_batch_one', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('rollback_batch_one'); }
};
PHP,
                '2026_07_04_000002_create_batch_two.php' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('rollback_batch_two', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('rollback_batch_two'); }
};
PHP,
            ]
        );

        try {
            $first = require $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_07_04_000001_create_batch_one.php';
            $first->up();
            SystemModuleMigration::query()->create([
                'module' => 'rollbacker',
                'migration' => '2026_07_04_000001_create_batch_one.php',
                'batch' => 1,
                'ran_at' => time(),
            ]);

            $second = require $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_07_04_000002_create_batch_two.php';
            $second->up();
            SystemModuleMigration::query()->create([
                'module' => 'rollbacker',
                'migration' => '2026_07_04_000002_create_batch_two.php',
                'batch' => 2,
                'ran_at' => time(),
            ]);

            app(\App\Modules\ModuleMigrationRunner::class)->rollbackRecorded($manifest);

            $this->assertTrue(Schema::hasTable('rollback_batch_one'));
            $this->assertFalse(Schema::hasTable('rollback_batch_two'));
            $this->assertDatabaseHas('system_module_migration', [
                'module' => 'rollbacker',
                'migration' => '2026_07_04_000001_create_batch_one.php',
                'batch' => 1,
            ]);
            $this->assertDatabaseMissing('system_module_migration', [
                'module' => 'rollbacker',
                'migration' => '2026_07_04_000002_create_batch_two.php',
            ]);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_assert_reversible_throws_when_recorded_migration_file_is_missing(): void
    {
        $root = storage_path('framework/testing-phase2-missing-file-assert');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'missingassert',
            'missingassert',
            []
        );

        try {
            SystemModuleMigration::query()->create([
                'module' => 'missingassert',
                'migration' => '2026_07_04_000001_missing.php',
                'batch' => 1,
                'ran_at' => time(),
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Recorded module migration file is missing');

            app(\App\Modules\ModuleMigrationRunner::class)->assertReversible($manifest);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_rollback_recorded_throws_when_recorded_migration_file_is_missing(): void
    {
        $root = storage_path('framework/testing-phase2-missing-file-rollback');
        $manifest = $this->createMigrationModuleFixture(
            $root,
            'missingrollback',
            'missingrollback',
            []
        );

        try {
            SystemModuleMigration::query()->create([
                'module' => 'missingrollback',
                'migration' => '2026_07_04_000001_missing.php',
                'batch' => 1,
                'ran_at' => time(),
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Recorded module migration file is missing');

            app(\App\Modules\ModuleMigrationRunner::class)->rollbackRecorded($manifest);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    /**
     * @throws JsonException
     */
    private function manifest(string $name, string $prefix): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name),
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $prefix,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>  $migrations
     */
    private function createMigrationModuleFixture(string $root, string $name, string $prefix, array $migrations): \App\Modules\ModuleManifest
    {
        $this->deleteDirectory($root);
        mkdir($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'module.json', $this->manifest($name, $prefix));

        foreach ($migrations as $filename => $contents) {
            file_put_contents($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.$filename, $contents);
        }

        return \App\Modules\ModuleManifest::fromFile($root.DIRECTORY_SEPARATOR.'module.json');
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
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
