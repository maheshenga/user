<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleOperation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ModuleOperationRecovery
{
    private const INTERMEDIATE_STATUSES = [
        'staging',
        'reviewing',
        'installing',
        'enabling',
        'disabling',
        'uninstalling',
        'upgrading',
        'rolling_back',
    ];

    private const RECOVERABLE_STATUSES = [
        'discovered',
        'pending_review',
        'approved',
        'rejected',
        'installed',
        'enabled',
        'disabled',
        'uninstalled',
    ];

    /**
     * @return array{examined: int, recovered: int, restored: int, skipped: int, operations: array<int, array<string, mixed>>}
     */
    public function recoverStale(CarbonInterface $before): array
    {
        $ids = SystemModuleOperation::query()
            ->where('status', 'running')
            ->where('heartbeat_at', '<', $before)
            ->orderBy('heartbeat_at')
            ->pluck('id');

        $result = [
            'examined' => $ids->count(),
            'recovered' => 0,
            'restored' => 0,
            'skipped' => 0,
            'operations' => [],
        ];

        foreach ($ids as $id) {
            $operationId = (string) $id;
            $recovered = DB::transaction(function () use ($operationId, $before): ?array {
                $operation = SystemModuleOperation::query()->lockForUpdate()->find($operationId);
                if (
                    $operation === null
                    || $operation->status !== 'running'
                    || $operation->heartbeat_at === null
                    || ! $operation->heartbeat_at->lt($before)
                ) {
                    return null;
                }

                $module = SystemModule::query()
                    ->where('name', $operation->module)
                    ->lockForUpdate()
                    ->first();
                $restored = false;

                if ($module !== null && $module->active_operation_id === $operation->id) {
                    $recoverableStatus = (string) ($operation->recoverable_status ?: $module->recoverable_status);
                    if (
                        in_array((string) $module->status, self::INTERMEDIATE_STATUSES, true)
                        && in_array($recoverableStatus, self::RECOVERABLE_STATUSES, true)
                    ) {
                        $module->status = $recoverableStatus;
                        $restored = true;
                    }

                    $module->forceFill([
                        'active_operation_id' => null,
                        'operation_started_at' => null,
                        'recoverable_status' => null,
                        'update_time' => time(),
                    ])->save();
                }

                $operation->forceFill([
                    'active_key' => null,
                    'stage' => 'recovered',
                    'status' => 'recovered',
                    'heartbeat_at' => now(),
                    'finished_at' => now(),
                    'error_message' => 'Recovered stale operation; module migrations were not reversed automatically.',
                ])->save();

                return [
                    'id' => (string) $operation->id,
                    'module' => (string) $operation->module,
                    'action' => (string) $operation->action,
                    'restored' => $restored,
                ];
            });

            if ($recovered === null) {
                $result['skipped']++;

                continue;
            }

            $result['recovered']++;
            $result['restored'] += $recovered['restored'] ? 1 : 0;
            $result['operations'][] = $recovered;
        }

        return $result;
    }
}
