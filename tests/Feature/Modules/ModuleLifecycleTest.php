<?php

namespace Tests\Feature\Modules;

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
}
