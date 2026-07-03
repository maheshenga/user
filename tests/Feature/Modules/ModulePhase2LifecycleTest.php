<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModuleMigration;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use JsonException;
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
        $this->deleteDirectory($root);
        mkdir($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'module.json', $this->manifest('migrator', 'migrator'));
        file_put_contents($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_07_04_000001_create_migrator_table.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('migrator_items', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('migrator_items'); }
};
PHP);

        $manifest = \App\Modules\ModuleManifest::fromFile($root.DIRECTORY_SEPARATOR.'module.json');

        try {
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);

            $this->assertTrue(Schema::hasTable('migrator_items'));
            $this->assertSame(1, SystemModuleMigration::query()->where('module', 'migrator')->count());
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
