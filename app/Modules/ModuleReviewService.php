<?php

namespace App\Modules;

use App\Models\SystemModuleRelease;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleReviewService
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleReleaseManager $releases,
        private readonly ModuleManifestPolicy $policy,
        private readonly ModuleReleaseSigner $signer,
        private readonly ModuleOperationCoordinator $operations,
    ) {}

    public function approve(string $name, ?int $actorId = null, ?string $trustLevel = null): void
    {
        $this->operations->run(
            $name,
            'approve_release',
            $actorId,
            fn (string $operationId) => $this->approveLocked($name, $actorId, $trustLevel, $operationId)
        );
    }

    private function approveLocked(
        string $name,
        ?int $actorId,
        ?string $trustLevel,
        string $operationId
    ): void {
        $this->operations->stage($operationId, 'reviewing');
        $module = $this->repository->installed($name);
        if ($module === null || $module->pending_release_id === null) {
            if ($module === null) {
                throw new InvalidArgumentException("模块 [{$name}] 不存在。");
            }

            $manifest = ModuleManifest::fromFile(
                rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
            );
            $this->releases->stageManifest($manifest, 'local', 'private', $actorId);
            $module = $this->repository->installed($name);
        }

        if ($module === null || $module->pending_release_id === null) {
            throw new InvalidArgumentException("模块 [{$name}] 没有待审核制品。");
        }
        $release = SystemModuleRelease::query()->findOrFail($module->pending_release_id);
        if (! in_array((string) $release->status, ['pending_review', 'rejected'], true)) {
            throw new InvalidArgumentException("模块 [{$name}] 当前制品状态 [{$release->status}] 不允许审核通过。");
        }

        $manifest = ModuleManifest::fromFile(rtrim((string) $release->artifact_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json');
        $this->policy->validate($manifest);
        $trustLevel ??= $release->source_type === 'local' ? 'private' : 'community';
        if (! in_array($trustLevel, (array) config('modules.allowed_types', []), true)) {
            throw new InvalidArgumentException('模块信任级别无效。');
        }

        $oldState = (string) $module->status;
        $targetState = in_array($oldState, ['pending_review', 'rejected'], true) ? 'approved' : $oldState;
        $this->operations->transition($operationId, $oldState, $targetState);

        DB::transaction(function () use ($module, $release, $trustLevel, $actorId): void {
            $oldState = (string) $module->status;
            $release->trust_level = $trustLevel;
            $release->signature_hash = $this->signer->sign($release);
            $release->status = 'approved';
            $release->reviewed_by = $actorId;
            $release->reviewed_at = now();
            $release->review_reason = null;
            $release->save();

            $payload = [
                'trust_level' => $trustLevel,
                'signature_hash' => $release->signature_hash,
                'last_error' => null,
                'update_time' => time(),
            ];
            if (in_array($oldState, ['pending_review', 'rejected'], true)) {
                $payload['status'] = 'approved';
            }
            $module->forceFill($payload)->save();

            $this->repository->log(
                'approve_release',
                (string) $module->name,
                $oldState,
                (string) ($payload['status'] ?? $oldState),
                'success',
                null,
                $actorId,
                (string) $module->version,
                (string) $release->version
            );
        });
        $this->operations->stage($operationId, 'approved');
    }

    public function reject(string $name, string $reason, ?int $actorId = null): void
    {
        $this->operations->run(
            $name,
            'reject_release',
            $actorId,
            fn (string $operationId) => $this->rejectLocked($name, $reason, $actorId, $operationId)
        );
    }

    private function rejectLocked(string $name, string $reason, ?int $actorId, string $operationId): void
    {
        $this->operations->stage($operationId, 'reviewing');
        $module = $this->repository->installed($name);
        if ($module === null || $module->pending_release_id === null) {
            $this->operations->transition($operationId, $module?->status, 'rejected');
            $this->repository->reject($name, $reason, $actorId);
            $this->operations->stage($operationId, 'rejected');

            return;
        }

        $release = SystemModuleRelease::query()->findOrFail($module->pending_release_id);
        if (! in_array((string) $release->status, ['pending_review', 'approved'], true)) {
            throw new InvalidArgumentException("模块 [{$name}] 当前制品状态 [{$release->status}] 不允许审核拒绝。");
        }

        $oldState = (string) $module->status;
        $targetState = in_array($oldState, ['pending_review', 'approved'], true) ? 'rejected' : $oldState;
        $this->operations->transition($operationId, $oldState, $targetState);

        DB::transaction(function () use ($module, $release, $reason, $actorId): void {
            $oldState = (string) $module->status;
            $release->forceFill([
                'status' => 'rejected',
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ])->save();
            $module->forceFill([
                'pending_release_id' => null,
                'last_error' => $reason,
                'status' => in_array($oldState, ['pending_review', 'approved'], true) ? 'rejected' : $oldState,
                'update_time' => time(),
            ])->save();
            $this->repository->log(
                'reject_release',
                (string) $module->name,
                $oldState,
                (string) $module->status,
                'success',
                $reason,
                $actorId,
                (string) $module->version,
                (string) $release->version
            );
        });
        $this->operations->stage($operationId, 'rejected');
    }
}
