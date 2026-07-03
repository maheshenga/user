<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModuleLog;
use App\Models\SystemModuleMigration;
use App\Models\SystemModuleSource;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
