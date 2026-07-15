<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Modules\ModuleExecutionPolicy;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRepository;
use App\Modules\ModuleReviewService;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleExecutionPolicyTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));

        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        Config::set('modules.path', base_path('tests/Fixtures/modules'));
        Config::set('modules.production_in_process_trust_levels', ['core', 'official', 'private']);
        Config::set('modules.signing_key', 'test-module-signing-key');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
    }

    public function test_production_rejects_enabling_a_community_module_without_eligible_worker(): void
    {
        $this->assertProductionEnableRejected('community_module', 'community');
    }

    public function test_production_rejects_enabling_a_partner_module_without_eligible_worker(): void
    {
        $this->assertProductionEnableRejected('partner_module', 'partner');
    }

    public function test_production_runtime_omits_stale_enabled_community_module(): void
    {
        $module = $this->createModule('stale_community', 'community', 'enabled');
        $this->app['env'] = 'production';

        $this->assertArrayNotHasKey($module->name, app(ModuleManager::class)->enabled());
        $this->assertStringContainsString(
            '不允许在生产环境主进程内运行',
            (string) $module->refresh()->last_error
        );
    }

    public function test_production_permits_enabling_a_private_immutable_release(): void
    {
        $manifest = app(ModuleManager::class)->manifest('blog');
        $this->assertNotNull($manifest);

        app(ModuleRepository::class)->upsertDiscovered($manifest);
        app(ModuleReviewService::class)->approve('blog', 1);
        app(ModuleInstaller::class)->install('blog', 1);
        $this->app['env'] = 'production';

        app(ModuleInstaller::class)->enable('blog', 1);

        $this->assertSame(
            'enabled',
            SystemModule::query()->where('name', 'blog')->value('status')
        );
    }

    public function test_non_production_allows_community_module_for_development(): void
    {
        $module = $this->createModule('local_community', 'community', 'disabled');

        $this->assertTrue(app(ModuleExecutionPolicy::class)->isInProcessAllowed($module));
        app(ModuleExecutionPolicy::class)->assertInProcessAllowed($module);

        $this->addToAssertionCount(1);
    }

    public function test_production_fails_closed_when_allowlist_configuration_is_invalid(): void
    {
        $module = $this->createModule('invalid_config', 'private', 'disabled');
        Config::set('modules.production_in_process_trust_levels', 'private');
        $this->app['env'] = 'production';

        $this->assertFalse(app(ModuleExecutionPolicy::class)->isInProcessAllowed($module));
    }

    public function test_production_never_allows_community_in_process_even_if_misconfigured(): void
    {
        $module = $this->createModule('misconfigured_community', 'community', 'disabled');
        Config::set('modules.production_in_process_trust_levels', ['core', 'official', 'private', 'community']);
        $this->app['env'] = 'production';

        $this->assertFalse(app(ModuleExecutionPolicy::class)->isInProcessAllowed($module));
    }

    public function test_production_uses_type_for_legacy_empty_trust_level(): void
    {
        $module = $this->createModule('legacy_private', 'private', 'disabled');
        $module->forceFill(['trust_level' => ''])->save();
        $this->app['env'] = 'production';

        $this->assertTrue(app(ModuleExecutionPolicy::class)->isInProcessAllowed($module->refresh()));
    }

    private function assertProductionEnableRejected(string $name, string $trustLevel): void
    {
        $module = $this->createModule($name, $trustLevel, 'disabled');
        $this->app['env'] = 'production';

        try {
            app(ModuleInstaller::class)->enable($name, 1);
            $this->fail("Expected [{$trustLevel}] module enablement to be rejected.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('未绑定可供 Worker 执行的已审核制品', $exception->getMessage());
        }

        $this->assertSame('disabled', $module->refresh()->status);
        $this->assertStringContainsString(
            '未绑定可供 Worker 执行的已审核制品',
            (string) $module->last_error
        );
        $this->assertDatabaseHas('system_module_log', [
            'module' => $name,
            'action' => 'enable',
            'result' => 'failed',
        ]);
        $this->assertDatabaseHas('system_module_operation', [
            'module' => $name,
            'action' => 'enable',
            'stage' => 'failed',
            'status' => 'failed',
            'actor_id' => 1,
        ]);
    }

    private function createModule(string $name, string $trustLevel, string $status): SystemModule
    {
        return SystemModule::query()->create([
            'name' => $name,
            'title' => $name,
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => $trustLevel,
            'trust_level' => $trustLevel,
            'status' => $status,
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => str_replace('_', '-', $name),
            'config_json' => [],
            'enabled_at' => $status === 'enabled' ? time() : null,
        ]);
    }
}
