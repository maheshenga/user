<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ModuleRollbacker
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleFileStore $files,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleArtifactHasher $hasher,
        private readonly ModuleManifestPolicy $policy,
        private readonly ModuleExecutionPolicy $executionPolicy,
        private readonly ModuleDependencyGraph $dependencyGraph,
        private readonly ModuleReleaseSigner $signer,
        private readonly ModuleMenuSynchronizer $menus,
        private readonly ModuleNodeSynchronizer $nodes,
        private readonly ModuleOperationCoordinator $operations,
    ) {}

    public function rollback(string $name, ?int $actorId = null): void
    {
        $this->operations->run($name, 'rollback', $actorId, function (string $operationId) use ($name, $actorId): void {
            $status = $this->repository->installed($name)?->status;
            $this->operations->transition($operationId, $status, $status);
            $this->operations->stage($operationId, 'rolling_back');
            $this->rollbackLocked($name, $actorId);
            $this->operations->stage($operationId, 'rolled_back');
        });
    }

    private function rollbackLocked(string $name, ?int $actorId): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("模块未安装：{$name}");
        }

        $status = (string) $module->status;
        if (! in_array($status, ['installed', 'enabled', 'disabled'], true)) {
            throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$status}] 不允许回滚。");
        }

        if ($module->active_release_id !== null) {
            if ($this->previousRelease($name, (int) $module->active_release_id) !== null) {
                $this->rollbackRelease($module, $status, $actorId);

                return;
            }
            if (! $this->executionPolicy->isExecutionInProcessAllowed($module)) {
                throw new RuntimeException("模块 [{$name}] 没有可供外部 Worker 回滚的已审核历史制品。");
            }
        }

        $restoreSource = null;
        $currentSource = null;
        $currentReplaced = false;
        $keepCurrentSource = false;

        try {
            $backup = $this->latestBackup($name);
            $target = ModuleManifest::fromFile($backup.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($target, $name);
            $this->policy->validate($target);
            $this->dependencyGraph->assertUpgradeCompatible($target);

            $currentPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR);
            $current = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($current, $name);
            $restoreSource = $this->files->copyToTemp($backup, 'rollback_restore_');
            $currentSource = $this->files->copyToTemp($currentPath, 'rollback_current_');
            $rollbackCurrent = ModuleManifest::fromFile($currentSource.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($rollbackCurrent, $name);

            $this->migrations->assertMissingReversible($rollbackCurrent, $target);
            if ($this->migrations->missingMigrationCount($rollbackCurrent, $target) > 1) {
                throw new RuntimeException('需要人工回滚：自动回滚最多支持一个缺失迁移。');
            }

            $this->migrations->rollbackMissingFrom($rollbackCurrent, $target);

            try {
                $this->files->replace($currentPath, $restoreSource);
            } catch (Throwable $exception) {
                $keepCurrentSource = true;

                throw new RuntimeException(
                    "迁移回滚后替换模块文件失败；当前文件已保留在 [{$currentSource}]：{$exception->getMessage()}",
                    0,
                    $exception
                );
            }
            $currentReplaced = true;
            $keepCurrentSource = true;

            $restored = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->repository->restoreVersion($restored, $status);
            $this->menus->sync($restored);
            $this->nodes->sync($restored);
            if ($status === 'disabled') {
                $this->menus->hide($name);
                $this->nodes->hide($name);
            }
            $this->repository->log('rollback', $name, $status, $status, 'success', null, $actorId);
            $this->clearCaches();
            $keepCurrentSource = false;
        } catch (Throwable $exception) {
            $this->repository->setLastError($name, $exception->getMessage());
            $this->repository->log('rollback', $name, $status, $status, 'failed', $exception->getMessage(), $actorId);

            throw $exception;
        } finally {
            if ($restoreSource !== null && is_dir($restoreSource)) {
                try {
                    $this->files->deleteDirectory($restoreSource);
                } catch (Throwable) {
                }
            }
            if (! $keepCurrentSource && $currentSource !== null && is_dir($currentSource)) {
                try {
                    $this->files->deleteDirectory($currentSource);
                } catch (Throwable) {
                }
            }
        }
    }

    private function rollbackRelease(SystemModule $module, string $status, ?int $actorId): void
    {
        $current = SystemModuleRelease::query()->findOrFail($module->active_release_id);
        $target = $this->previousRelease((string) $module->name, (int) $current->id);
        if ($target === null) {
            throw new RuntimeException("未找到模块历史制品：{$module->name}");
        }

        try {
            $currentManifest = $this->verifiedManifest($current);
            $targetManifest = $this->verifiedManifest($target);
            $this->assertManifestName($targetManifest, (string) $module->name);
            $this->dependencyGraph->assertUpgradeCompatible($targetManifest);
            $this->executionPolicy->assertReleaseExecutionAllowed($module, $target);
            $inProcess = $this->executionPolicy->isReleaseInProcessAllowed($module, $target);
            if ($inProcess) {
                $this->migrations->assertMissingReversible($currentManifest, $targetManifest);
                if ($this->migrations->missingMigrationCount($currentManifest, $targetManifest) > 1) {
                    throw new RuntimeException('需要人工回滚：自动回滚最多支持一个缺失迁移。');
                }
            }

            DB::transaction(function () use ($module, $status, $actorId, $current, $target, $currentManifest, $targetManifest, $inProcess): void {
                if ($inProcess) {
                    $this->migrations->rollbackMissingFrom($currentManifest, $targetManifest);
                }
                $current->forceFill(['status' => 'superseded'])->save();
                $target->forceFill(['status' => 'active', 'activated_at' => now()])->save();
                $module->forceFill([
                    'title' => $targetManifest->title(),
                    'vendor' => $targetManifest->vendor(),
                    'version' => $targetManifest->version(),
                    'type' => $targetManifest->type(),
                    'trust_level' => $target->trust_level,
                    'status' => $status,
                    'path' => $targetManifest->path(),
                    'namespace' => $targetManifest->namespace(),
                    'admin_prefix' => $targetManifest->adminPrefix(),
                    'signature_hash' => $target->signature_hash,
                    'active_release_id' => $target->id,
                    'pending_release_id' => null,
                    'config_json' => $targetManifest->toArray(),
                    'last_error' => null,
                    'update_time' => time(),
                ])->save();
                if ($inProcess) {
                    $this->menus->sync($targetManifest);
                    $this->nodes->sync($targetManifest);
                } else {
                    $this->menus->hide((string) $module->name);
                    $this->nodes->hide((string) $module->name);
                }
                if ($status === 'disabled') {
                    $this->menus->hide((string) $module->name);
                    $this->nodes->hide((string) $module->name);
                }
                $this->repository->log(
                    'rollback_release',
                    (string) $module->name,
                    $status,
                    $status,
                    'success',
                    null,
                    $actorId,
                    $currentManifest->version(),
                    $targetManifest->version()
                );
            });

            $this->clearCaches();
        } catch (Throwable $exception) {
            $this->repository->setLastError((string) $module->name, $exception->getMessage());
            $this->repository->log(
                'rollback_release',
                (string) $module->name,
                $status,
                $status,
                'failed',
                $exception->getMessage(),
                $actorId,
                (string) $current->version,
                (string) $target->version
            );

            throw $exception;
        }
    }

    private function previousRelease(string $name, int $currentReleaseId): ?SystemModuleRelease
    {
        return SystemModuleRelease::query()
            ->where('module', $name)
            ->whereKeyNot($currentReleaseId)
            ->where('status', 'superseded')
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function verifiedManifest(SystemModuleRelease $release): ModuleManifest
    {
        if (! $this->signer->verify($release)) {
            throw new RuntimeException("模块 [{$release->module}] 历史制品签名校验失败。");
        }
        $actualHash = $this->hasher->hashDirectory((string) $release->artifact_path);
        if (! hash_equals((string) $release->artifact_hash, $actualHash)) {
            throw new RuntimeException("模块 [{$release->module}] 历史制品完整性校验失败。");
        }

        $manifest = ModuleManifest::fromFile(
            rtrim((string) $release->artifact_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
        );
        $this->policy->validate($manifest);

        return $manifest;
    }

    private function latestBackup(string $name): string
    {
        $root = storage_path('modules/backups/'.$name);
        $entries = is_dir($root)
            ? array_values(array_filter(scandir($root) ?: [], static function (string $entry) use ($root): bool {
                $path = $root.DIRECTORY_SEPARATOR.$entry;

                return $entry !== '.' && $entry !== '..' && is_dir($path) && is_file($path.DIRECTORY_SEPARATOR.'module.json');
            }))
            : [];
        usort($entries, static function (string $left, string $right) use ($root): int {
            preg_match('/^(\d{14})-/', $left, $leftMatches);
            preg_match('/^(\d{14})-/', $right, $rightMatches);
            $leftTimestamp = (int) ($leftMatches[1] ?? 0);
            $rightTimestamp = (int) ($rightMatches[1] ?? 0);
            $leftTime = filemtime($root.DIRECTORY_SEPARATOR.$left) ?: 0;
            $rightTime = filemtime($root.DIRECTORY_SEPARATOR.$right) ?: 0;

            return ($rightTimestamp <=> $leftTimestamp)
                ?: ($rightTime <=> $leftTime)
                ?: strcmp($right, $left);
        });

        foreach ($entries as $entry) {
            $path = $root.DIRECTORY_SEPARATOR.$entry;

            return $path;
        }

        throw new RuntimeException("未找到模块备份：{$name}");
    }

    private function assertManifestName(ModuleManifest $manifest, string $expectedName): void
    {
        if ($manifest->name() !== $expectedName) {
            throw new InvalidArgumentException("期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。");
        }
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
