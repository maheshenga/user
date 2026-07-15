<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleOperation;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleOperationCoordinator;
use App\Modules\ModuleOperationRecovery;
use App\Modules\ModuleReviewService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;
use Throwable;

class ModuleOperationStateTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
    }

    public function test_module_operation_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasTable('system_module_operation'));
        $this->assertTrue(Schema::hasColumns('system_module_operation', [
            'id',
            'module',
            'active_key',
            'action',
            'previous_status',
            'target_status',
            'recoverable_status',
            'stage',
            'status',
            'actor_id',
            'started_at',
            'heartbeat_at',
            'finished_at',
            'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('system_module', [
            'active_operation_id',
            'operation_started_at',
            'recoverable_status',
        ]));
    }

    public function test_operation_model_uses_uuid_identity_and_casts_dates(): void
    {
        $this->assertTrue(class_exists(SystemModuleOperation::class));

        $operation = SystemModuleOperation::query()->create([
            'id' => (string) Str::uuid(),
            'module' => 'model_test',
            'active_key' => 'model_test',
            'action' => 'test',
            'stage' => 'claimed',
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $this->assertIsString($operation->getKey());
        $this->assertFalse($operation->getIncrementing());
        $this->assertSame('string', $operation->getKeyType());
        $this->assertNotNull($operation->started_at?->toIso8601String());
        $this->assertNotNull($operation->heartbeat_at?->toIso8601String());
    }

    public function test_operation_coordinator_service_is_available(): void
    {
        $this->assertTrue(class_exists(ModuleOperationCoordinator::class));
        $this->assertTrue(method_exists(ModuleOperationCoordinator::class, 'run'));
        $this->assertTrue(method_exists(ModuleOperationCoordinator::class, 'stage'));
        $this->assertTrue(method_exists(ModuleOperationCoordinator::class, 'transition'));
        $this->assertSame(
            app(ModuleOperationCoordinator::class),
            app(ModuleOperationCoordinator::class)
        );
    }

    public function test_two_active_operations_for_one_module_cannot_coexist(): void
    {
        $module = $this->createModule('locked_module', 'enabled');
        $active = $this->createRunningOperation('locked_module', 'activate');
        $module->forceFill([
            'active_operation_id' => $active->id,
            'operation_started_at' => $active->started_at,
            'recoverable_status' => 'enabled',
        ])->save();

        $coordinator = app(ModuleOperationCoordinator::class);
        $this->assertTrue(method_exists($coordinator, 'run'));

        try {
            $coordinator->run('locked_module', 'disable', 2, static fn (): null => null);
            $this->fail('A second active operation should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('locked_module', $exception->getMessage());
        }

        $this->assertSame(1, SystemModuleOperation::query()->where('module', 'locked_module')->count());
        $this->assertSame('running', $active->refresh()->status);
    }

    public function test_nested_same_module_operations_reuse_the_operation_id(): void
    {
        $module = $this->createModule('nested_module', 'enabled');
        $coordinator = app(ModuleOperationCoordinator::class);
        $this->assertTrue(method_exists($coordinator, 'run'));
        $operationIds = [];

        $result = $coordinator->run('nested_module', 'outer', 1, function (string $outerId) use ($coordinator, &$operationIds): string {
            $operationIds[] = $outerId;
            $coordinator->stage($outerId, 'outer_running');

            return $coordinator->run('nested_module', 'inner', 1, function (string $innerId) use (&$operationIds): string {
                $operationIds[] = $innerId;

                return 'nested-result';
            });
        });

        $this->assertSame('nested-result', $result);
        $this->assertCount(2, $operationIds);
        $this->assertSame($operationIds[0], $operationIds[1]);
        $this->assertSame(1, SystemModuleOperation::query()->where('module', 'nested_module')->count());

        $operation = SystemModuleOperation::query()->findOrFail($operationIds[0]);
        $this->assertSame('outer', $operation->action);
        $this->assertSame('succeeded', $operation->status);
        $this->assertSame('completed', $operation->stage);
        $this->assertNull($operation->active_key);
        $this->assertNull($module->refresh()->active_operation_id);
    }

    public function test_different_modules_can_operate_independently(): void
    {
        $this->createModule('first_module', 'enabled');
        $this->createModule('second_module', 'disabled');
        $coordinator = app(ModuleOperationCoordinator::class);
        $this->assertTrue(method_exists($coordinator, 'run'));

        $ids = $coordinator->run('first_module', 'outer', 1, function (string $firstId) use ($coordinator): array {
            $secondId = $coordinator->run('second_module', 'inner', 1, static fn (string $id): string => $id);

            return [$firstId, $secondId];
        });

        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame(2, SystemModuleOperation::query()->where('status', 'succeeded')->count());
    }

    public function test_transition_records_recoverable_and_target_status(): void
    {
        $this->createModule('transition_module', 'enabled');
        $coordinator = app(ModuleOperationCoordinator::class);
        $this->assertTrue(method_exists($coordinator, 'run'));

        $operationId = $coordinator->run('transition_module', 'activate', 3, function (string $id) use ($coordinator): string {
            $coordinator->transition($id, 'enabled', 'enabled');
            $coordinator->stage($id, 'migrating');

            return $id;
        });

        $operation = SystemModuleOperation::query()->findOrFail($operationId);
        $this->assertSame('enabled', $operation->previous_status);
        $this->assertSame('enabled', $operation->recoverable_status);
        $this->assertSame('enabled', $operation->target_status);
        $this->assertNotNull($operation->finished_at);
    }

    public function test_failed_operation_clears_marker_and_redacts_persisted_error(): void
    {
        $module = $this->createModule('failed_module', 'disabled');
        $coordinator = app(ModuleOperationCoordinator::class);
        $this->assertTrue(method_exists($coordinator, 'run'));

        try {
            $coordinator->run('failed_module', 'enable', 4, function (string $id) use ($coordinator): never {
                $coordinator->stage($id, 'enabling');

                throw new RuntimeException('Bearer bearer-value token=token-value password=password-value secret=secret-value');
            });
            $this->fail('The operation exception should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bearer-value', $exception->getMessage());
        }

        $operation = SystemModuleOperation::query()->where('module', 'failed_module')->firstOrFail();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('failed', $operation->stage);
        $this->assertNull($operation->active_key);
        $this->assertNotNull($operation->finished_at);
        $this->assertStringContainsString('[REDACTED]', (string) $operation->error_message);
        $this->assertStringNotContainsString('bearer-value', (string) $operation->error_message);
        $this->assertStringNotContainsString('token-value', (string) $operation->error_message);
        $this->assertStringNotContainsString('password-value', (string) $operation->error_message);
        $this->assertStringNotContainsString('secret-value', (string) $operation->error_message);

        $module->refresh();
        $this->assertNull($module->active_operation_id);
        $this->assertNull($module->operation_started_at);
        $this->assertNull($module->recoverable_status);
    }

    public function test_stale_upgrading_operation_restores_recoverable_status(): void
    {
        $this->assertTrue(class_exists(ModuleOperationRecovery::class));
        $module = $this->createModule('stale_module', 'upgrading');
        $operation = $this->createRunningOperation('stale_module', 'activate');
        $staleAt = now()->subMinutes(10);
        $operation->forceFill([
            'previous_status' => 'enabled',
            'recoverable_status' => 'enabled',
            'started_at' => $staleAt,
            'heartbeat_at' => $staleAt,
        ])->save();
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => $staleAt,
            'recoverable_status' => 'enabled',
        ])->save();

        $result = app(ModuleOperationRecovery::class)->recoverStale(now()->subMinutes(5));

        $this->assertSame(1, $result['examined']);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(1, $result['restored']);
        $this->assertSame('enabled', $module->refresh()->status);
        $this->assertNull($module->active_operation_id);
        $this->assertSame('recovered', $operation->refresh()->status);
        $this->assertSame('recovered', $operation->stage);
        $this->assertNull($operation->active_key);
        $this->assertNotNull($operation->finished_at);
    }

    public function test_fresh_operation_is_not_recovered(): void
    {
        $this->assertTrue(class_exists(ModuleOperationRecovery::class));
        $module = $this->createModule('fresh_module', 'upgrading');
        $operation = $this->createRunningOperation('fresh_module', 'activate');
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => now(),
            'recoverable_status' => 'enabled',
        ])->save();

        $result = app(ModuleOperationRecovery::class)->recoverStale(now()->subMinutes(5));

        $this->assertSame(0, $result['examined']);
        $this->assertSame(0, $result['recovered']);
        $this->assertSame('upgrading', $module->refresh()->status);
        $this->assertSame($operation->id, $module->active_operation_id);
        $this->assertSame('running', $operation->refresh()->status);
    }

    public function test_recovery_never_overwrites_a_stable_module_status(): void
    {
        $this->assertTrue(class_exists(ModuleOperationRecovery::class));
        $module = $this->createModule('stable_module', 'disabled');
        $operation = $this->createRunningOperation('stable_module', 'disable');
        $staleAt = now()->subMinutes(10);
        $operation->forceFill([
            'recoverable_status' => 'enabled',
            'started_at' => $staleAt,
            'heartbeat_at' => $staleAt,
        ])->save();
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => $staleAt,
            'recoverable_status' => 'enabled',
        ])->save();

        $result = app(ModuleOperationRecovery::class)->recoverStale(now()->subMinutes(5));

        $this->assertSame(1, $result['recovered']);
        $this->assertSame(0, $result['restored']);
        $this->assertSame('disabled', $module->refresh()->status);
        $this->assertNull($module->active_operation_id);
    }

    public function test_approve_and_reject_share_the_module_lifecycle_lock(): void
    {
        $module = $this->createModule('review_module', 'pending_review');
        $operation = $this->createRunningOperation('review_module', 'reject');
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => now(),
            'recoverable_status' => 'pending_review',
        ])->save();

        try {
            app(ModuleReviewService::class)->approve('review_module', 8);
            $this->fail('Approve should not race an active reject operation.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertStringContainsString('active lifecycle operation', $exception->getMessage());
        }

        $this->assertSame('pending_review', $module->refresh()->status);
        $this->assertSame('running', $operation->refresh()->status);
    }

    public function test_activation_operation_blocks_disable(): void
    {
        $module = $this->createModule('activate_module', 'enabled');
        $operation = $this->createRunningOperation('activate_module', 'activate_release');
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => now(),
            'recoverable_status' => 'enabled',
        ])->save();

        try {
            app(ModuleInstaller::class)->disable('activate_module', 9);
            $this->fail('Disable should not race an active release activation.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertStringContainsString('active lifecycle operation', $exception->getMessage());
        }

        $this->assertSame('enabled', $module->refresh()->status);
        $this->assertSame('running', $operation->refresh()->status);
    }

    public function test_recovery_command_supports_json_output(): void
    {
        $module = $this->createModule('command_module', 'upgrading');
        $operation = $this->createRunningOperation('command_module', 'activate_release');
        $staleAt = now()->subMinutes(10);
        $operation->forceFill([
            'recoverable_status' => 'enabled',
            'started_at' => $staleAt,
            'heartbeat_at' => $staleAt,
        ])->save();
        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => $staleAt,
            'recoverable_status' => 'enabled',
        ])->save();

        $this->artisan('system:module-operations:recover', [
            '--minutes' => 5,
            '--json' => true,
        ])->expectsOutputToContain('"recovered":1')->assertExitCode(0);

        $this->assertSame('enabled', $module->refresh()->status);
        $this->assertSame('recovered', $operation->refresh()->status);
    }

    private function createModule(string $name, string $status): SystemModule
    {
        return SystemModule::query()->create([
            'name' => $name,
            'title' => ucfirst(str_replace('_', ' ', $name)),
            'vendor' => 'Tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => $status,
            'path' => base_path('modules/'.Str::studly($name)),
            'namespace' => 'Tests\\Modules\\'.Str::studly($name),
            'admin_prefix' => $name,
            'config_json' => [],
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createRunningOperation(string $module, string $action): SystemModuleOperation
    {
        return SystemModuleOperation::query()->create([
            'id' => (string) Str::uuid(),
            'module' => $module,
            'active_key' => $module,
            'action' => $action,
            'previous_status' => 'enabled',
            'recoverable_status' => 'enabled',
            'stage' => 'claimed',
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }
}
