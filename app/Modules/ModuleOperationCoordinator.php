<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ModuleOperationCoordinator
{
    /**
     * @var array<string, array{id: string, depth: int}>
     */
    private array $active = [];

    public function run(string $module, string $action, ?int $actorId, callable $operation): mixed
    {
        $this->assertModuleName($module);

        if (isset($this->active[$module])) {
            $this->active[$module]['depth']++;

            try {
                return $operation($this->active[$module]['id']);
            } finally {
                $this->active[$module]['depth']--;
            }
        }

        $lock = $this->acquireFileLock($module);

        try {
            $operationId = $this->claim($module, $action, $actorId);
            $this->active[$module] = ['id' => $operationId, 'depth' => 1];

            try {
                $result = $operation($operationId);
            } catch (Throwable $exception) {
                $this->finish($operationId, 'failed', $exception);

                throw $exception;
            } finally {
                unset($this->active[$module]);
            }

            $this->finish($operationId, 'succeeded');

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function stage(string $operationId, string $stage): void
    {
        if ($stage === '' || strlen($stage) > 80) {
            throw new RuntimeException('Module operation stage must be between 1 and 80 characters.');
        }

        DB::transaction(function () use ($operationId, $stage): void {
            $operation = SystemModuleOperation::query()->lockForUpdate()->findOrFail($operationId);
            $this->assertRunning($operation);
            $operation->forceFill([
                'stage' => $stage,
                'heartbeat_at' => now(),
            ])->save();
            $this->attachModuleMarker($operation);
        });
    }

    public function transition(
        string $operationId,
        ?string $recoverableStatus,
        ?string $targetStatus
    ): void {
        DB::transaction(function () use ($operationId, $recoverableStatus, $targetStatus): void {
            $operation = SystemModuleOperation::query()->lockForUpdate()->findOrFail($operationId);
            $this->assertRunning($operation);
            $operation->forceFill([
                'recoverable_status' => $recoverableStatus,
                'target_status' => $targetStatus,
                'heartbeat_at' => now(),
            ])->save();
            $this->attachModuleMarker($operation);
        });
    }

    private function claim(string $module, string $action, ?int $actorId): string
    {
        try {
            return DB::transaction(function () use ($module, $action, $actorId): string {
                $moduleRow = SystemModule::query()->where('name', $module)->lockForUpdate()->first();
                if ($moduleRow?->active_operation_id !== null) {
                    throw $this->busy($module);
                }

                $operationId = (string) Str::uuid();
                $now = now();
                SystemModuleOperation::query()->create([
                    'id' => $operationId,
                    'module' => $module,
                    'active_key' => $module,
                    'action' => $action,
                    'previous_status' => $moduleRow?->status,
                    'recoverable_status' => $moduleRow?->status,
                    'stage' => 'claimed',
                    'status' => 'running',
                    'actor_id' => $actorId,
                    'started_at' => $now,
                    'heartbeat_at' => $now,
                ]);

                if ($moduleRow !== null) {
                    $moduleRow->forceFill([
                        'active_operation_id' => $operationId,
                        'operation_started_at' => $now,
                        'recoverable_status' => $moduleRow->status,
                    ])->save();
                }

                return $operationId;
            });
        } catch (QueryException $exception) {
            if (SystemModuleOperation::query()->where('active_key', $module)->exists()) {
                throw $this->busy($module, $exception);
            }

            throw $exception;
        }
    }

    private function finish(string $operationId, string $status, ?Throwable $exception = null): void
    {
        DB::transaction(function () use ($operationId, $status, $exception): void {
            $operation = SystemModuleOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null || $operation->status !== 'running') {
                return;
            }

            $operation->forceFill([
                'active_key' => null,
                'stage' => $status === 'succeeded' ? 'completed' : 'failed',
                'status' => $status,
                'heartbeat_at' => now(),
                'finished_at' => now(),
                'error_message' => $exception === null ? null : $this->redactError($exception->getMessage()),
            ])->save();

            SystemModule::query()
                ->where('name', $operation->module)
                ->where('active_operation_id', $operationId)
                ->update([
                    'active_operation_id' => null,
                    'operation_started_at' => null,
                    'recoverable_status' => null,
                    'update_time' => time(),
                ]);
        });
    }

    private function attachModuleMarker(SystemModuleOperation $operation): void
    {
        $module = SystemModule::query()->where('name', $operation->module)->lockForUpdate()->first();
        if ($module === null) {
            return;
        }
        if ($module->active_operation_id !== null && $module->active_operation_id !== $operation->id) {
            throw $this->busy((string) $operation->module);
        }

        $module->forceFill([
            'active_operation_id' => $operation->id,
            'operation_started_at' => $operation->started_at,
            'recoverable_status' => $operation->recoverable_status,
            'update_time' => time(),
        ])->save();
    }

    private function assertRunning(SystemModuleOperation $operation): void
    {
        if ($operation->status !== 'running' || $operation->active_key === null) {
            throw new RuntimeException("Module operation [{$operation->id}] is no longer running.");
        }
    }

    /**
     * @return resource
     */
    private function acquireFileLock(string $module)
    {
        $dir = storage_path('modules/locks');
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create module lock directory: {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.$this->safeLockSegment($module).'.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Unable to open module lock: {$path}");
        }

        $deadline = microtime(true) + 2.0;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        throw $this->busy($module);
    }

    private function assertModuleName(string $module): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/', $module) !== 1) {
            throw new RuntimeException('Invalid module name for lifecycle operation.');
        }
    }

    private function safeLockSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?: '_';
    }

    private function busy(string $module, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException("Module [{$module}] already has an active lifecycle operation.", 0, $previous);
    }

    private function redactError(string $message): string
    {
        $redacted = preg_replace(
            '/\bBearer\s+[^\s,;]+/i',
            'Bearer [REDACTED]',
            $message
        ) ?? $message;
        $redacted = preg_replace(
            '/\b(token|password|secret|api[_-]?key|authorization)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $redacted
        ) ?? $redacted;

        return Str::limit($redacted, 4000, '');
    }
}
