<?php

namespace App\Modules;

use App\Models\ModuleApiRequest;
use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Models\SystemModuleOperation;
use App\Models\SystemModuleRelease;
use App\Models\UserApiRefreshToken;
use App\Models\UserApiSession;
use App\Models\UserNotificationOutbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ModuleRetentionService
{
    public function __construct(private readonly ModuleFileStore $files) {}

    /**
     * @return array{before: string, limit: int, deleted: array<string, int>, artifact_failures: int, total_deleted: int}
     */
    public function prune(CarbonInterface $before, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $deleted = [
            'module_api_requests' => $this->pruneModuleApiRequests($before, $limit),
            'refresh_tokens' => $this->pruneRefreshTokens($before, $limit),
            'api_sessions' => 0,
            'releases' => 0,
            'artifacts' => 0,
            'module_logs' => $this->pruneModuleLogs($before, $limit),
            'notifications' => $this->pruneNotifications($before, $limit),
            'operations' => $this->pruneOperations($before, $limit),
        ];

        [$deleted['api_sessions'], $cascadedTokens] = $this->pruneApiSessions($before, $limit);
        $deleted['refresh_tokens'] += $cascadedTokens;
        [$deleted['releases'], $deleted['artifacts'], $artifactFailures] = $this->pruneReleases($before, $limit);

        return [
            'before' => $before->toIso8601String(),
            'limit' => $limit,
            'deleted' => $deleted,
            'artifact_failures' => $artifactFailures,
            'total_deleted' => array_sum($deleted),
        ];
    }

    private function pruneModuleApiRequests(CarbonInterface $before, int $limit): int
    {
        if (! Schema::hasTable('module_api_request')) {
            return 0;
        }

        $ids = ModuleApiRequest::query()
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before)
            ->orderBy('finished_at')
            ->limit($limit)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : ModuleApiRequest::query()->whereIn('id', $ids)->delete();
    }

    private function pruneRefreshTokens(CarbonInterface $before, int $limit): int
    {
        if (! Schema::hasTable('user_api_refresh_tokens')) {
            return 0;
        }

        $ids = UserApiRefreshToken::query()
            ->where(function ($query) use ($before): void {
                $query->where('expires_at', '<', $before)
                    ->orWhere(function ($query) use ($before): void {
                        $query->whereNotNull('used_at')->where('updated_at', '<', $before);
                    })
                    ->orWhere(function ($query) use ($before): void {
                        $query->whereNotNull('revoked_at')->where('updated_at', '<', $before);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : UserApiRefreshToken::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return array{int, int}
     */
    private function pruneApiSessions(CarbonInterface $before, int $limit): array
    {
        if (! Schema::hasTable('user_api_sessions')) {
            return [0, 0];
        }

        $ids = UserApiSession::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $before)
            ->orderBy('revoked_at')
            ->limit($limit)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return [0, 0];
        }

        $tokens = UserApiRefreshToken::query()->whereIn('session_id', $ids)->count();
        $sessions = UserApiSession::query()->whereIn('id', $ids)->delete();

        return [$sessions, $tokens];
    }

    /**
     * @return array{int, int, int}
     */
    private function pruneReleases(CarbonInterface $before, int $limit): array
    {
        if (! Schema::hasTable('system_module_release')) {
            return [0, 0, 0];
        }

        $protectedIds = SystemModule::query()
            ->get(['active_release_id', 'pending_release_id'])
            ->flatMap(static fn (SystemModule $module): array => [
                $module->active_release_id,
                $module->pending_release_id,
            ])
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach (SystemModule::query()->pluck('name') as $module) {
            $rollbackId = SystemModuleRelease::query()
                ->where('module', $module)
                ->where('status', 'superseded')
                ->orderByDesc('activated_at')
                ->orderByDesc('id')
                ->value('id');
            if ($rollbackId !== null) {
                $protectedIds[] = (int) $rollbackId;
            }
        }

        $releases = SystemModuleRelease::query()
            ->whereIn('status', ['rejected', 'failed', 'superseded'])
            ->where('created_at', '<', $before)
            ->when($protectedIds !== [], static fn ($query) => $query->whereNotIn('id', array_unique($protectedIds)))
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'artifact_path']);
        if ($releases->isEmpty()) {
            return [0, 0, 0];
        }

        $deletableIds = [];
        $artifactCount = 0;
        $artifactFailures = 0;
        foreach ($releases->groupBy('artifact_path') as $path => $pathReleases) {
            $pathReleaseIds = $pathReleases->pluck('id');
            if (
                SystemModuleRelease::query()
                    ->where('artifact_path', $path)
                    ->whereNotIn('id', $pathReleaseIds)
                    ->exists()
                || SystemModule::query()->where('path', $path)->exists()
            ) {
                array_push($deletableIds, ...$pathReleaseIds->all());

                continue;
            }

            $artifactExists = file_exists((string) $path) || is_link((string) $path);
            try {
                $this->files->deleteDirectory((string) $path);
            } catch (Throwable) {
                $artifactFailures++;

                continue;
            }

            if ($artifactExists) {
                $artifactCount++;
            }
            array_push($deletableIds, ...$pathReleaseIds->all());
        }

        $releaseCount = $deletableIds === []
            ? 0
            : SystemModuleRelease::query()->whereIn('id', $deletableIds)->delete();

        return [$releaseCount, $artifactCount, $artifactFailures];
    }

    private function pruneModuleLogs(CarbonInterface $before, int $limit): int
    {
        if (! Schema::hasTable('system_module_log')) {
            return 0;
        }

        $ids = SystemModuleLog::query()
            ->whereNull('admin_id')
            ->where('result', 'success')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before->timestamp)
            ->orderBy('finished_at')
            ->limit($limit)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : SystemModuleLog::query()->whereIn('id', $ids)->delete();
    }

    private function pruneNotifications(CarbonInterface $before, int $limit): int
    {
        if (! Schema::hasTable('user_notification_outbox')) {
            return 0;
        }

        $ids = UserNotificationOutbox::query()
            ->where('status', 'sent')
            ->where('create_time', '<', $before->timestamp)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : UserNotificationOutbox::query()->whereIn('id', $ids)->delete();
    }

    private function pruneOperations(CarbonInterface $before, int $limit): int
    {
        if (! Schema::hasTable('system_module_operation')) {
            return 0;
        }

        $ids = SystemModuleOperation::query()
            ->whereIn('status', ['succeeded', 'failed', 'recovered'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before)
            ->orderBy('finished_at')
            ->limit($limit)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : SystemModuleOperation::query()->whereIn('id', $ids)->delete();
    }
}
