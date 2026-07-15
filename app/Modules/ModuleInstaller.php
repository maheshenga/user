<?php

namespace App\Modules;

use App\User\UserApiTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class ModuleInstaller
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly ModuleExecutionPolicy $executionPolicy,
        private readonly ModuleManifestPolicy $manifestPolicy,
        private readonly ModuleDependencyGraph $dependencyGraph,
        private readonly ModuleRepository $repository,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleVersionRecorder $versions,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleReleaseManager $releases,
        private readonly ModuleMenuSynchronizer $menus,
        private readonly ModuleNodeSynchronizer $nodes,
        private readonly UserApiTokenService $apiTokens,
        private readonly ModuleOperationCoordinator $operations,
    ) {}

    public function install(string $name, ?int $actorId = null): void
    {
        $current = $this->repository->installed($name);
        if ($current !== null && $current->pending_release_id !== null) {
            $this->releases->activateApproved($name, $actorId);

            return;
        }

        $manifest = $this->manager->manifest($name);
        if ($manifest === null) {
            throw new InvalidArgumentException("模块不存在：{$name}");
        }

        $current = $this->repository->installed($name);
        if ($current === null) {
            $this->repository->upsertDiscovered($manifest);
            $current = $this->repository->installed($name);
        }

        $oldState = $current?->status;
        $newState = $this->installTargetState($oldState);

        $this->runLifecycleAction('install', $name, $oldState, $newState, $actorId, function () use ($current, $manifest, $name, $newState): void {
            if ($current !== null && in_array($current->status, ['pending_review', 'rejected'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 必须先通过审核才能安装。");
            }

            $this->manifestPolicy->validate($manifest);
            $this->dependencyGraph->assertUpgradeCompatible($manifest);
            $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $name);
            if ($current === null) {
                throw new InvalidArgumentException("模块 [{$name}] 安装记录不存在。");
            }
            $this->repository->upsertDiscovered($manifest);
            $this->versions->record($manifest);
            if ($this->executionPolicy->isExecutionInProcessAllowed($current)) {
                $this->menus->sync($manifest);
                $this->nodes->sync($manifest);
                $this->migrations->runPending($manifest);
            } else {
                $this->menus->hide($name);
                $this->nodes->hide($name);
            }
            if ($newState === 'disabled') {
                $this->menus->hide($name);
                $this->nodes->hide($name);
            }
            $this->repository->setStatus($name, $newState);
        }, function () use ($name): void {
            $module = $this->repository->installed($name);
            if ($module !== null && ! in_array($module->status, ['pending_review', 'rejected'], true)) {
                $this->executionPolicy->assertExecutionAllowed($module);
            }
        });
    }

    public function enable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('enable', $name, $oldState, 'enabled', $actorId, function () use ($name): void {
            $module = $this->repository->installed($name);
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            $this->reservedPrefixes->assertAllowed((string) $module->admin_prefix, $name);

            if (! in_array($module->status, ['installed', 'disabled'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许启用。");
            }

            if (
                app()->environment('production')
                && Schema::hasTable('system_module_release')
                && $module->active_release_id === null
            ) {
                throw new InvalidArgumentException("模块 [{$name}] 必须先纳入已审核的不可变制品才能启用。");
            }

            $manifest = ModuleManifest::fromFile(
                rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
            );
            $this->manifestPolicy->validate($manifest);
            $this->dependencyGraph->assertUpgradeCompatible($manifest);
            $this->dependencyGraph->activationOrder($name);
            if ($this->executionPolicy->isExecutionInProcessAllowed($module)) {
                $this->menus->sync($manifest);
                $this->nodes->sync($manifest);
            } else {
                $this->menus->hide($name);
                $this->nodes->hide($name);
            }
            $this->repository->setStatus($name, 'enabled');
        }, function () use ($name): void {
            $module = $this->repository->installed($name);
            if ($module !== null && in_array($module->status, ['installed', 'disabled'], true)) {
                $this->executionPolicy->assertExecutionAllowed($module);
            }
        });
    }

    public function disable(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('disable', $name, $oldState, 'disabled', $actorId, function () use ($name): void {
            $module = $this->repository->installed($name);
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            if ($module->status !== 'enabled') {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许禁用。");
            }

            $this->dependencyGraph->assertCanDisable($name);
            $this->menus->hide($name);
            $this->nodes->hide($name);
            $this->apiTokens->revokeModule($name);
            $this->repository->setStatus($name, 'disabled');
        });
    }

    public function uninstallPreserve(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        $oldState = $module?->status;

        $this->runLifecycleAction('uninstall', $name, $oldState, 'uninstalled', $actorId, function () use ($name): void {
            $module = $this->repository->installed($name);
            if ($module === null) {
                throw new InvalidArgumentException("模块未安装：{$name}");
            }

            if (! in_array($module->status, ['installed', 'disabled', 'enabled'], true)) {
                throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$module->status}] 不允许卸载。");
            }

            $this->dependencyGraph->assertCanDisable($name);
            $this->menus->hide($name);
            $this->nodes->hide($name);
            $this->apiTokens->revokeModule($name);
            $this->repository->setStatus($name, 'uninstalled');
        });
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }

    private function installTargetState(?string $currentState): string
    {
        return match ($currentState) {
            'enabled', 'disabled', 'installed' => $currentState,
            default => 'installed',
        };
    }

    /**
     * @param  callable(): void  $operation
     * @param  null|callable(): void  $preflight
     */
    private function runLifecycleAction(
        string $action,
        string $name,
        ?string $oldState,
        ?string $newState,
        ?int $actorId,
        callable $operation,
        ?callable $preflight = null
    ): void {
        $this->operations->run($name, $action, $actorId, function (string $operationId) use (
            $action,
            $name,
            $oldState,
            $newState,
            $actorId,
            $operation,
            $preflight
        ): void {
            $currentState = $this->repository->installed($name)?->status ?? $oldState;
            $this->operations->transition($operationId, $currentState, $newState);
            $this->operations->stage($operationId, 'executing_lifecycle');

            try {
                if ($preflight !== null) {
                    $preflight();
                }
                DB::transaction(function () use ($action, $name, $currentState, $newState, $actorId, $operation): void {
                    $operation();
                    $this->repository->log($action, $name, $currentState, $newState, 'success', null, $actorId);
                });
            } catch (Throwable $exception) {
                $this->repository->setLastError($name, $exception->getMessage());
                $this->repository->log($action, $name, $currentState, $currentState, 'failed', $exception->getMessage(), $actorId);

                throw $exception;
            }

            $this->repository->setLastError($name, null);
            $this->clearCaches();
            $this->operations->stage($operationId, 'lifecycle_applied');
        });
    }
}
