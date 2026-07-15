<?php

namespace App\Modules;

use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ModuleReleaseManager
{
    public function __construct(
        private readonly ModuleZipExtractor $zips,
        private readonly ModuleArtifactHasher $hasher,
        private readonly ModuleArtifactStore $artifacts,
        private readonly ModuleManifestPolicy $policy,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleVersionRecorder $versions,
        private readonly ModuleRepository $repository,
        private readonly ModuleReleaseSigner $signer,
        private readonly ModuleFileStore $files,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleMenuSynchronizer $menus,
        private readonly ModuleOperationCoordinator $operations,
    ) {}

    public function stageZip(string $zipPath, ?string $expectedName = null, ?int $actorId = null): SystemModuleRelease
    {
        $extracted = $this->zips->extract($zipPath);
        $manifest = null;

        try {
            $manifest = ModuleManifest::fromFile($extracted.DIRECTORY_SEPARATOR.'module.json');
            if ($expectedName !== null && $expectedName !== '' && $manifest->name() !== $expectedName) {
                throw new InvalidArgumentException("期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。");
            }

            return $this->stageManifest($manifest, 'zip', 'community', $actorId);
        } catch (Throwable $exception) {
            $name = $manifest?->name() ?? $expectedName;
            if (is_string($name) && $name !== '') {
                $module = $this->repository->installed($name);
                if ($module !== null) {
                    $this->repository->setLastError($name, $exception->getMessage());
                }
                $this->repository->log(
                    'stage_release',
                    $name,
                    $module?->status,
                    $module?->status,
                    'failed',
                    $exception->getMessage(),
                    $actorId,
                    $module?->version,
                    $manifest?->version()
                );
            }

            throw $exception;
        } finally {
            $this->cleanupExtracted($extracted);
        }
    }

    public function stageManifest(
        ModuleManifest $manifest,
        string $sourceType = 'local',
        string $trustLevel = 'private',
        ?int $actorId = null
    ): SystemModuleRelease {
        return $this->operations->run(
            $manifest->name(),
            'stage_release',
            $actorId,
            fn (string $operationId): SystemModuleRelease => $this->stageManifestLocked(
                $manifest,
                $sourceType,
                $trustLevel,
                $actorId,
                $operationId
            )
        );
    }

    private function stageManifestLocked(
        ModuleManifest $manifest,
        string $sourceType,
        string $trustLevel,
        ?int $actorId,
        string $operationId
    ): SystemModuleRelease {
        $this->operations->stage($operationId, 'validating');
        $this->policy->validate($manifest);
        $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $manifest->name());
        $current = $this->repository->installed($manifest->name());
        if ($current !== null && in_array((string) $current->status, ['installed', 'enabled', 'disabled'], true)) {
            $comparison = version_compare($manifest->version(), (string) $current->version);
            if (($current->active_release_id !== null && $comparison <= 0) || ($current->active_release_id === null && $comparison < 0)) {
                throw new InvalidArgumentException(
                    "模块 [{$manifest->name()}] 新版本 [{$manifest->version()}] 必须大于当前版本 [{$current->version}]。"
                );
            }
        }

        $hash = $this->hasher->hashDirectory($manifest->path());
        $artifactPath = $this->artifacts->stage($manifest, $hash);
        $artifactManifest = ModuleManifest::fromFile($artifactPath.DIRECTORY_SEPARATOR.'module.json');
        $artifactPath = $artifactManifest->path();
        $this->operations->stage($operationId, 'persisting_release');

        $release = DB::transaction(function () use ($artifactManifest, $artifactPath, $hash, $sourceType, $trustLevel, $actorId, $current): SystemModuleRelease {
            $release = SystemModuleRelease::query()->firstOrCreate(
                [
                    'module' => $artifactManifest->name(),
                    'version' => $artifactManifest->version(),
                    'artifact_hash' => $hash,
                ],
                [
                    'source_type' => $sourceType,
                    'trust_level' => $trustLevel,
                    'artifact_path' => $artifactPath,
                    'manifest_json' => $artifactManifest->toArray(),
                    'status' => 'pending_review',
                    'previous_status' => $current?->status,
                    'uploaded_by' => $actorId,
                ]
            );
            if (! $release->wasRecentlyCreated && in_array((string) $release->status, ['rejected', 'failed', 'superseded'], true)) {
                $release->forceFill([
                    'source_type' => $sourceType,
                    'trust_level' => $trustLevel,
                    'artifact_path' => $artifactPath,
                    'manifest_json' => $artifactManifest->toArray(),
                    'status' => 'pending_review',
                    'signature_hash' => null,
                    'uploaded_by' => $actorId,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                ])->save();
            }

            if ($current !== null && $current->pending_release_id !== null && (int) $current->pending_release_id !== (int) $release->id) {
                SystemModuleRelease::query()
                    ->whereKey($current->pending_release_id)
                    ->whereIn('status', ['pending_review', 'approved'])
                    ->update(['status' => 'superseded']);
            }

            if ($current === null) {
                $this->repository->upsertDiscovered($artifactManifest);
                $current = $this->repository->installed($artifactManifest->name());
            }

            $current?->forceFill([
                'pending_release_id' => $release->id,
                'last_error' => null,
                'update_time' => time(),
            ])->save();
            $this->repository->log(
                'stage_release',
                $artifactManifest->name(),
                $current?->status,
                $current?->status,
                'success',
                null,
                $actorId,
                $current?->version,
                $artifactManifest->version()
            );

            return $release->refresh();
        });

        $this->operations->stage($operationId, 'staged');

        return $release;
    }

    public function activateApproved(string $name, ?int $actorId = null): void
    {
        $this->operations->run(
            $name,
            'activate_release',
            $actorId,
            fn (string $operationId) => $this->activateApprovedLocked($name, $actorId, $operationId)
        );
    }

    private function activateApprovedLocked(string $name, ?int $actorId, string $operationId): void
    {
        $module = $this->repository->installed($name);
        if ($module === null || $module->pending_release_id === null) {
            throw new InvalidArgumentException("模块 [{$name}] 没有待激活版本。");
        }

        $release = SystemModuleRelease::query()->findOrFail($module->pending_release_id);
        if ($release->status !== 'approved') {
            throw new InvalidArgumentException("模块 [{$name}] 待激活版本尚未通过审核。");
        }
        if (! $this->signer->verify($release)) {
            throw new RuntimeException("模块 [{$name}] 制品签名校验失败。");
        }

        $manifest = ModuleManifest::fromFile(rtrim((string) $release->artifact_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');
        $this->policy->validate($manifest);
        $actualHash = $this->hasher->hashDirectory((string) $release->artifact_path);
        if (! hash_equals((string) $release->artifact_hash, $actualHash)) {
            throw new RuntimeException("模块 [{$name}] 制品完整性校验失败。");
        }

        $oldStatus = (string) $module->status;
        $targetStatus = in_array($oldStatus, ['installed', 'enabled', 'disabled'], true) ? $oldStatus : 'installed';
        $oldVersion = (string) $module->version;
        $oldReleaseId = $module->active_release_id;
        $this->operations->transition($operationId, $oldStatus, $targetStatus);
        $this->operations->stage($operationId, 'migrating');
        $module->forceFill(['status' => 'upgrading', 'update_time' => time()])->save();

        $migrationBatch = null;
        try {
            $migrationBatch = $this->migrations->runPending($manifest);
            $this->operations->stage($operationId, 'activating');

            DB::transaction(function () use (
                $module,
                $manifest,
                $release,
                $targetStatus,
                $oldReleaseId,
                $oldStatus,
                $oldVersion,
                $actorId
            ): void {
                $this->menus->sync(
                    $manifest,
                    $oldReleaseId === null && in_array($oldStatus, ['installed', 'enabled', 'disabled'], true)
                );
                if ($oldReleaseId !== null) {
                    SystemModuleRelease::query()->whereKey($oldReleaseId)->update(['status' => 'superseded']);
                }

                $module->forceFill([
                    'title' => $manifest->title(),
                    'vendor' => $manifest->vendor(),
                    'version' => $manifest->version(),
                    'type' => $manifest->type(),
                    'trust_level' => $release->trust_level,
                    'status' => $targetStatus,
                    'path' => $manifest->path(),
                    'namespace' => $manifest->namespace(),
                    'admin_prefix' => $manifest->adminPrefix(),
                    'signature_hash' => $release->signature_hash,
                    'active_release_id' => $release->id,
                    'pending_release_id' => null,
                    'config_json' => $manifest->toArray(),
                    'last_error' => null,
                    'update_time' => time(),
                ])->save();

                $release->forceFill([
                    'status' => 'active',
                    'activated_at' => now(),
                ])->save();
                $this->versions->record($manifest);
                $this->repository->log(
                    $oldReleaseId === null ? 'install_release' : 'activate_release',
                    $manifest->name(),
                    $oldStatus,
                    $targetStatus,
                    'success',
                    null,
                    $actorId,
                    $oldVersion,
                    $manifest->version()
                );
            });
        } catch (Throwable $exception) {
            $failure = $exception;
            if ($migrationBatch !== null) {
                try {
                    $this->migrations->rollbackRecorded($manifest, $migrationBatch);
                } catch (Throwable $rollbackException) {
                    $failure = new RuntimeException(
                        "模块激活失败 [{$exception->getMessage()}]，迁移补偿也失败：{$rollbackException->getMessage()}",
                        0,
                        $exception
                    );
                }
            }
            $module->forceFill([
                'status' => $oldStatus,
                'last_error' => $failure->getMessage(),
                'update_time' => time(),
            ])->save();
            $release->forceFill(['status' => 'failed'])->save();
            $this->repository->log(
                'activate_release',
                $name,
                $oldStatus,
                $oldStatus,
                'failed',
                $failure->getMessage(),
                $actorId,
                $oldVersion,
                (string) $release->version
            );

            throw $failure;
        }

        $this->operations->stage($operationId, 'activated');
        $this->clearCaches();
    }

    private function cleanupExtracted(string $path): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = dirname($path);
        $tmp = str_replace('\\', '/', storage_path('modules/tmp'));

        if (is_dir($path)) {
            $this->files->deleteDirectory($path);
        }
        if (is_dir($parent) && str_starts_with(str_replace('\\', '/', $parent), rtrim($tmp, '/').'/')) {
            $entries = array_values(array_filter(scandir($parent) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
            if ($entries === []) {
                $this->files->deleteDirectory($parent);
            }
        }
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
