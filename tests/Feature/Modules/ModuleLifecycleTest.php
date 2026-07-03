<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Models\SystemModuleMigration;
use App\Models\SystemModuleSource;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleLifecycleTest extends TestCase
{
    use CreatesModuleTestSchema;

    public function test_module_tables_are_created_by_migrations(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->assertTrue(Schema::hasTable('system_module'));
        $this->assertTrue(Schema::hasTable('system_module_version'));
        $this->assertTrue(Schema::hasTable('system_module_migration'));
        $this->assertTrue(Schema::hasTable('system_module_log'));
        $this->assertTrue(Schema::hasTable('system_module_source'));
    }

    public function test_module_schema_contract_matches_phase_1_requirements(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->assertTrue(Schema::hasColumns('system_module', [
            'name',
            'status',
            'admin_prefix',
            'config_json',
            'delete_time',
        ]));
        $this->assertSame('discovered', $this->getSqliteColumnDefault('system_module', 'status'));
        $this->assertSame('private', $this->getSqliteColumnDefault('system_module', 'trust_level'));
        $this->assertUniqueIndex('system_module', ['name']);
        $this->assertUniqueIndex('system_module', ['admin_prefix']);

        $this->assertTrue(Schema::hasColumns('system_module_log', [
            'module',
            'action',
            'result',
            'error_message',
        ]));

        $this->assertTrue(Schema::hasColumns('system_module_migration', [
            'module',
            'migration',
            'batch',
        ]));
        $this->assertSame('1', $this->getSqliteColumnDefault('system_module_migration', 'batch'));
        $this->assertUniqueIndex('system_module_migration', ['module', 'migration']);
    }

    public function test_models_without_delete_time_column_can_query_tables(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->assertSame(0, SystemModuleLog::query()->count());
        $this->assertSame(0, SystemModuleMigration::query()->count());
        $this->assertSame(0, SystemModuleVersion::query()->count());
        $this->assertSame(0, SystemModuleSource::query()->count());
    }

    public function test_module_can_be_installed_enabled_disabled_and_logged(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $this->artisan('module:install', ['name' => 'blog'])->assertExitCode(0);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'installed']);
        $this->assertDatabaseHas('system_menu', ['href' => 'blog/post/index']);
        $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'install', 'result' => 'success']);

        $this->artisan('module:enable', ['name' => 'blog'])->assertExitCode(0);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'enabled']);
        $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'enable', 'result' => 'success']);

        $this->artisan('module:disable', ['name' => 'blog'])->assertExitCode(0);
        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'disabled']);
        $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'disable', 'result' => 'success']);
    }

    public function test_module_commands_fail_gracefully_when_module_tables_are_missing(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        Schema::drop('system_module_source');
        Schema::drop('system_module_log');
        Schema::drop('system_module_migration');
        Schema::drop('system_module_version');
        Schema::drop('system_module');

        $this->artisan('module:list')
            ->expectsOutputToContain('Module tables are not installed')
            ->assertExitCode(1);

        foreach (['discover', 'install', 'enable', 'disable', 'uninstall'] as $command) {
            $parameters = match ($command) {
                'install', 'enable', 'disable', 'uninstall' => ['name' => 'blog'],
                default => [],
            };

            $this->artisan("module:{$command}", $parameters)
                ->expectsOutputToContain('Module tables are not installed')
                ->assertExitCode(1);
        }
    }

    public function test_enable_requires_module_to_be_installed_or_disabled(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        app(\App\Modules\ModuleRepository::class)->upsertDiscovered(app(\App\Modules\ModuleManager::class)->manifest('blog'));

        $this->artisan('module:enable', ['name' => 'blog'])
            ->expectsOutputToContain('cannot be enabled from status [discovered]')
            ->assertExitCode(1);

        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'discovered']);
    }

    public function test_repeated_install_refreshes_manifest_without_downgrading_enabled_and_dedupes_menus(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $this->artisan('module:install', ['name' => 'blog'])->assertExitCode(0);
        $this->artisan('module:enable', ['name' => 'blog'])->assertExitCode(0);
        $beforeCount = DB::table('system_menu')->count();

        $this->artisan('module:install', ['name' => 'blog'])->assertExitCode(0);

        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'enabled']);
        $this->assertSame($beforeCount, DB::table('system_menu')->count());
        $this->assertSame(1, DB::table('system_menu')->where('href', 'blog/post/index')->count());
    }

    public function test_install_uses_empty_icon_for_module_menus_without_icon(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $root = storage_path('framework/testing-modules-menu-icon');
        $manifest = json_decode($this->moduleManifest('plain_menu', 'plainmenu'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['menus'] = [
            [
                'title' => 'Plain Menu',
                'href' => 'plainmenu/post/index',
            ],
        ];
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'PlainMenu', json_encode($manifest, JSON_THROW_ON_ERROR));
        \Illuminate\Support\Facades\Config::set('modules.path', $root);

        try {
            $this->artisan('module:install', ['name' => 'plain_menu'])->assertExitCode(0);

            $this->assertSame('', DB::table('system_menu')->where('href', 'plainmenu/post/index')->value('icon'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_uninstall_preserve_allows_enabled_and_marks_module_uninstalled(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $this->artisan('module:install', ['name' => 'blog'])->assertExitCode(0);
        $this->artisan('module:enable', ['name' => 'blog'])->assertExitCode(0);

        $this->artisan('module:uninstall', ['name' => 'blog'])->assertExitCode(0);

        $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'uninstalled']);
        $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'uninstall', 'result' => 'success']);
    }

    public function test_install_failure_rolls_back_partial_changes_and_logs_failure(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));
        Schema::drop('system_menu');

        $this->artisan('module:install', ['name' => 'blog'])
            ->expectsOutputToContain('no such table: system_menu')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('system_module', ['name' => 'blog']);
        $this->assertDatabaseHas('system_module_log', [
            'module' => 'blog',
            'action' => 'install',
            'result' => 'failed',
        ]);
    }

    public function test_install_fails_for_modules_using_reserved_admin_prefixes_and_logs_failure(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $root = storage_path('framework/testing-modules-reserved-install');
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'MallCollision', $this->moduleManifest('mall_collision', 'mall'));
        $this->ensureModuleFixture($root.DIRECTORY_SEPARATOR.'SystemCollision', $this->moduleManifest('system_collision', 'system'));
        \Illuminate\Support\Facades\Config::set('modules.path', $root);

        try {
            foreach ([
                ['mall_collision', 'mall'],
                ['system_collision', 'system'],
            ] as [$name, $prefix]) {
                $this->artisan('module:install', ['name' => $name])
                    ->expectsOutputToContain("reserved admin_prefix [{$prefix}]")
                    ->assertExitCode(1);

                $this->assertDatabaseMissing('system_module', ['name' => $name, 'status' => 'installed']);
                $this->assertDatabaseHas('system_module_log', [
                    'module' => $name,
                    'action' => 'install',
                    'result' => 'failed',
                ]);
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_enable_fails_for_installed_module_using_reserved_admin_prefix(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

        SystemModule::query()->create([
            'name' => 'dirty_mall',
            'title' => 'Dirty Mall Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'installed',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'mall',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'installed_at' => time(),
        ]);

        $this->artisan('module:enable', ['name' => 'dirty_mall'])
            ->expectsOutputToContain('reserved admin_prefix [mall]')
            ->assertExitCode(1);

        $this->assertDatabaseHas('system_module', ['name' => 'dirty_mall', 'status' => 'installed']);
        $this->assertDatabaseHas('system_module_log', [
            'module' => 'dirty_mall',
            'action' => 'enable',
            'result' => 'failed',
        ]);
    }

    /**
     * @return array<int, object>
     */
    protected function getSqliteTableInfo(string $table): array
    {
        return DB::select("PRAGMA table_info('{$table}')");
    }

    protected function getSqliteColumnDefault(string $table, string $column): ?string
    {
        foreach ($this->getSqliteTableInfo($table) as $definition) {
            if ($definition->name === $column) {
                return $definition->dflt_value === null
                    ? null
                    : trim((string) $definition->dflt_value, "'\"");
            }
        }

        $this->fail("Column [{$column}] was not found on table [{$table}].");
    }

    /**
     * @return array<int, array{unique:int, columns:array<int, string>}>
     */
    protected function getSqliteIndexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
            $columns = [];

            foreach (DB::select("PRAGMA index_info('{$index->name}')") as $column) {
                $columns[] = $column->name;
            }

            $indexes[] = [
                'unique' => (int) $index->unique,
                'columns' => $columns,
            ];
        }

        return $indexes;
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected function assertUniqueIndex(string $table, array $columns): void
    {
        foreach ($this->getSqliteIndexes($table) as $index) {
            if ($index['unique'] === 1 && $index['columns'] === $columns) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("Unique index [".implode(', ', $columns)."] was not found on table [{$table}].");
    }

    /**
     * @throws JsonException
     */
    private function moduleManifest(string $name, string $adminPrefix): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name).' Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.str_replace(' ', '', ucwords(str_replace('_', ' ', $name))),
            'entry' => 'src/Providers/'.str_replace(' ', '', ucwords(str_replace('_', ' ', $name))).'ServiceProvider.php',
            'admin_prefix' => $adminPrefix,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
            'seeders' => 'database/seeders',
            'permissions' => [],
            'external_domains' => [],
            'dependencies' => new \stdClass(),
            'conflicts' => new \stdClass(),
            'database' => [
                'tables' => [],
                'preserve_on_uninstall' => true,
            ],
            'menus' => [],
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
