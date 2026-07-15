<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Modules\ModuleDependencyGraph;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManifest;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleDependencyGraphTest extends TestCase
{
    use CreatesModuleTestSchema;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->root = storage_path('framework/testing-module-dependency-graph');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_activation_order_places_enabled_dependencies_before_module(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('foundation', '1.2.0', 'enabled');
        $this->createModule('application', '1.0.0', 'installed', ['foundation' => '^1.0']);

        $this->assertSame(
            ['foundation', 'application'],
            app(ModuleDependencyGraph::class)->activationOrder('application')
        );
    }

    public function test_dependency_cycle_is_rejected(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('module_a', '1.0.0', 'installed', ['module_b' => '^1.0']);
        $this->createModule('module_b', '1.0.0', 'enabled', ['module_a' => '^1.0']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('模块依赖存在循环');

        app(ModuleDependencyGraph::class)->activationOrder('module_a');
    }

    public function test_reverse_dependent_blocks_disabling_dependency(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('foundation', '1.0.0', 'enabled');
        $this->createModule('application', '1.0.0', 'enabled', ['foundation' => '^1.0']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('模块 [foundation] 仍被模块 [application] 依赖');

        app(ModuleDependencyGraph::class)->assertCanDisable('foundation');
    }

    public function test_module_installer_checks_reverse_dependencies_before_disabling(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('foundation', '1.0.0', 'enabled');
        $this->createModule('application', '1.0.0', 'enabled', ['foundation' => '^1.0']);

        try {
            app(ModuleInstaller::class)->disable('foundation', 1);
            $this->fail('A required module must not be disabled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('仍被模块 [application] 依赖', $exception->getMessage());
        }

        $this->assertSame('enabled', SystemModule::query()->where('name', 'foundation')->value('status'));
    }

    public function test_reverse_dependent_blocks_incompatible_upgrade(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('foundation', '1.0.0', 'enabled');
        $this->createModule('application', '1.0.0', 'enabled', ['foundation' => '^1.0']);
        $candidate = $this->manifest('foundation', '2.0.0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('升级后将不满足模块 [application] 的依赖约束');

        app(ModuleDependencyGraph::class)->assertUpgradeCompatible($candidate);
    }

    public function test_installed_module_reverse_conflict_blocks_candidate(): void
    {
        $this->assertTrue(class_exists(ModuleDependencyGraph::class));
        $this->createModule('legacy_module', '1.0.0', 'enabled', [], ['new_module' => '*']);
        $candidate = $this->manifest('new_module', '1.0.0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('已安装模块 [legacy_module] 与候选模块 [new_module] 冲突');

        app(ModuleDependencyGraph::class)->assertUpgradeCompatible($candidate);
    }

    private function createModule(
        string $name,
        string $version,
        string $status,
        array $dependencies = [],
        array $conflicts = []
    ): SystemModule {
        $manifest = $this->manifestData($name, $version, $dependencies, $conflicts);

        return SystemModule::query()->create([
            'name' => $name,
            'title' => ucfirst(str_replace('_', ' ', $name)),
            'vendor' => 'testing',
            'version' => $version,
            'type' => 'private',
            'trust_level' => 'private',
            'status' => $status,
            'path' => $this->root.DIRECTORY_SEPARATOR.$name,
            'namespace' => $manifest['namespace'],
            'admin_prefix' => $name,
            'config_json' => $manifest,
        ]);
    }

    private function manifest(
        string $name,
        string $version,
        array $dependencies = [],
        array $conflicts = []
    ): ModuleManifest {
        $directory = $this->root.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'module.json';
        file_put_contents(
            $path,
            json_encode($this->manifestData($name, $version, $dependencies, $conflicts), JSON_THROW_ON_ERROR)
        );

        return ModuleManifest::fromFile($path);
    }

    private function manifestData(
        string $name,
        string $version,
        array $dependencies = [],
        array $conflicts = []
    ): array {
        return [
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst(str_replace('_', ' ', $name)),
            'vendor' => 'testing',
            'version' => $version,
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.str_replace(' ', '', ucwords(str_replace('_', ' ', $name))),
            'admin_prefix' => $name,
            'permissions' => [],
            'dependencies' => $dependencies,
            'conflicts' => $conflicts,
            'gateway_versions' => [],
        ];
    }
}
