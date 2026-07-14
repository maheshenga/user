<?php

namespace App\Modules;

use App\Models\SystemModuleMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ModuleMigrationRunner
{
    public function runPending(ModuleManifest $manifest): ?int
    {
        $path = $manifest->migrationsPath();

        if ($path === null || ! is_dir($path)) {
            return null;
        }

        $pendingFiles = $this->pendingFiles($manifest, $path);

        if ($pendingFiles === []) {
            return null;
        }

        $batch = $this->nextBatch($manifest->name());
        $applied = [];

        try {
            foreach ($pendingFiles as $file) {
                if ($this->applyPendingFile($manifest, $file, $batch)) {
                    $applied[] = $file;
                }
            }
        } catch (Throwable $exception) {
            try {
                $this->compensateApplied($manifest, $applied);
            } catch (Throwable $cleanupException) {
                throw new RuntimeException(
                    "模块迁移批次在原始失败 [{$exception->getMessage()}] 后执行补偿失败：{$cleanupException->getMessage()}",
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return $batch;
    }

    private function applyPendingFile(ModuleManifest $manifest, string $file, int $batch): bool
    {
        $migration = basename($file);

        return DB::transaction(function () use ($manifest, $migration, $batch, $file): bool {
            if (SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                return false;
            }

            $instance = require $file;
            if (! is_object($instance) || ! method_exists($instance, 'up')) {
                throw new RuntimeException("模块迁移 [{$migration}] 必须返回包含 up() 方法的对象。");
            }

            try {
                $instance->up();
            } catch (Throwable $exception) {
                if (method_exists($instance, 'down')) {
                    try {
                        $instance->down();
                    } catch (Throwable $cleanupException) {
                        throw new RuntimeException(
                            "模块迁移 [{$migration}] 在原始失败 [{$exception->getMessage()}] 后执行清理失败：{$cleanupException->getMessage()}",
                            0,
                            $exception
                        );
                    }
                }

                throw $exception;
            }

            try {
                SystemModuleMigration::query()->create([
                    'module' => $manifest->name(),
                    'migration' => $migration,
                    'batch' => $batch,
                    'ran_at' => time(),
                ]);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateTrackingRow($exception)) {
                    throw $exception;
                }

                if (method_exists($instance, 'down')) {
                    $instance->down();
                }

                return false;
            }

            return true;
        });
    }

    /**
     * @param  array<int, string>  $files
     */
    private function compensateApplied(ModuleManifest $manifest, array $files): void
    {
        foreach (array_reverse($files) as $file) {
            $migration = basename($file);
            DB::transaction(function () use ($manifest, $file, $migration): void {
                $instance = require $file;
                if (! is_object($instance) || ! method_exists($instance, 'down')) {
                    throw new RuntimeException("模块迁移补偿被不可逆迁移阻止：{$migration}");
                }

                $instance->down();
                SystemModuleMigration::query()
                    ->where('module', $manifest->name())
                    ->where('migration', $migration)
                    ->delete();
            });
        }
    }

    public function assertReversible(ModuleManifest $manifest, ?int $batch = null): void
    {
        foreach ($this->recordedFiles($manifest, $batch) as $file) {
            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('模块回滚被不可逆迁移阻止：'.basename($file));
            }
        }
    }

    public function rollbackRecorded(ModuleManifest $manifest, ?int $batch = null): void
    {
        foreach (array_reverse($this->recordedFiles($manifest, $batch)) as $file) {
            $migration = basename($file);
            DB::transaction(function () use ($manifest, $migration, $file): void {
                if (! SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                    return;
                }

                $instance = require $file;

                if (! is_object($instance) || ! method_exists($instance, 'down')) {
                    throw new RuntimeException('模块回滚被不可逆迁移阻止：'.$migration);
                }

                $instance->down();

                SystemModuleMigration::query()
                    ->where('module', $manifest->name())
                    ->where('migration', $migration)
                    ->delete();
            });
        }
    }

    public function assertMissingReversible(ModuleManifest $current, ModuleManifest $target): void
    {
        foreach ($this->recordedMissingFiles($current, $target) as $file) {
            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('模块回滚被不可逆迁移阻止：'.basename($file));
            }
        }
    }

    public function missingMigrationCount(ModuleManifest $current, ModuleManifest $target): int
    {
        return count($this->recordedMissingFiles($current, $target));
    }

    public function rollbackMissingFrom(ModuleManifest $current, ModuleManifest $target): void
    {
        $files = array_reverse($this->recordedMissingFiles($current, $target));

        DB::transaction(function () use ($current, $files): void {
            foreach ($files as $file) {
                $migration = basename($file);

                if (! SystemModuleMigration::query()->where('module', $current->name())->where('migration', $migration)->exists()) {
                    continue;
                }

                $instance = require $file;

                if (! is_object($instance) || ! method_exists($instance, 'down')) {
                    throw new RuntimeException('模块回滚被不可逆迁移阻止：'.$migration);
                }

                $instance->down();

                SystemModuleMigration::query()
                    ->where('module', $current->name())
                    ->where('migration', $migration)
                    ->delete();
            }
        });
    }

    private function nextBatch(string $module): int
    {
        return ((int) SystemModuleMigration::query()->where('module', $module)->max('batch')) + 1;
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(string $path): array
    {
        $files = glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function recordedFiles(ModuleManifest $manifest, ?int $batch = null): array
    {
        $path = $manifest->migrationsPath();
        $query = SystemModuleMigration::query()->where('module', $manifest->name());
        $targetBatch = $batch ?? (int) $query->max('batch');

        if ($targetBatch < 1) {
            return [];
        }

        $records = SystemModuleMigration::query()
            ->where('module', $manifest->name())
            ->where('batch', $targetBatch)
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        if ($path === null || ! is_dir($path)) {
            throw new RuntimeException('已记录的模块迁移文件缺失：'.(string) $records->first()->migration);
        }

        $files = [];

        foreach ($records as $record) {
            $file = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$record->migration;

            if (! is_file($file)) {
                throw new RuntimeException('已记录的模块迁移文件缺失：'.$record->migration);
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function recordedMissingFiles(ModuleManifest $current, ModuleManifest $target): array
    {
        $currentPath = $current->migrationsPath();
        $targetPath = $target->migrationsPath();
        $targetMap = $targetPath !== null && is_dir($targetPath)
            ? array_fill_keys(array_map('basename', $this->migrationFiles($targetPath)), true)
            : [];
        $records = SystemModuleMigration::query()
            ->where('module', $current->name())
            ->orderBy('id')
            ->get();
        $files = [];

        foreach ($records as $record) {
            $migration = (string) $record->migration;
            if (isset($targetMap[$migration])) {
                continue;
            }

            if ($currentPath === null || ! is_dir($currentPath)) {
                throw new RuntimeException('已记录的模块迁移文件缺失：'.$migration);
            }

            $file = rtrim($currentPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$migration;
            if (! is_file($file)) {
                throw new RuntimeException('已记录的模块迁移文件缺失：'.$migration);
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function pendingFiles(ModuleManifest $manifest, string $path): array
    {
        $recorded = SystemModuleMigration::query()
            ->where('module', $manifest->name())
            ->pluck('migration')
            ->all();

        $recordedMap = array_fill_keys($recorded, true);

        return array_values(array_filter(
            $this->migrationFiles($path),
            static fn (string $file): bool => ! isset($recordedMap[basename($file)])
        ));
    }

    private function isDuplicateTrackingRow(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return (str_contains($message, 'UNIQUE constraint failed')
                && str_contains($message, 'system_module_migration'))
            || str_contains($message, 'Duplicate entry');
    }
}
