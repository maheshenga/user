<?php

namespace App\Modules;

use App\Models\SystemModuleMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ModuleMigrationRunner
{
    public function runPending(ModuleManifest $manifest): void
    {
        $path = $manifest->migrationsPath();

        if ($path === null || ! is_dir($path)) {
            return;
        }

        $pendingFiles = $this->pendingFiles($manifest, $path);

        if ($pendingFiles === []) {
            return;
        }

        $batch = $this->nextBatch($manifest->name());

        foreach ($pendingFiles as $file) {
            $migration = basename($file);

            DB::transaction(function () use ($manifest, $migration, $batch, $file): void {
                if (SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                    return;
                }

                $instance = require $file;

                if (! is_object($instance) || ! method_exists($instance, 'up')) {
                    throw new RuntimeException("Module migration [{$migration}] must return an object with up().");
                }

                try {
                    $instance->up();
                } catch (Throwable $exception) {
                    if (method_exists($instance, 'down')) {
                        try {
                            $instance->down();
                        } catch (Throwable $cleanupException) {
                            throw new RuntimeException(
                                "Module migration cleanup failed for [{$migration}] after original failure [{$exception->getMessage()}]: {$cleanupException->getMessage()}",
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
                    if ($this->isDuplicateTrackingRow($exception)) {
                        return;
                    }

                    throw $exception;
                }
            });
        }
    }

    public function assertReversible(ModuleManifest $manifest, ?int $batch = null): void
    {
        foreach ($this->recordedFiles($manifest, $batch) as $file) {
            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('Module rollback blocked by irreversible migration: '.basename($file));
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
                    throw new RuntimeException('Module rollback blocked by irreversible migration: '.$migration);
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
                throw new RuntimeException('Module rollback blocked by irreversible migration: '.basename($file));
            }
        }
    }

    public function rollbackMissingFrom(ModuleManifest $current, ModuleManifest $target): void
    {
        foreach (array_reverse($this->recordedMissingFiles($current, $target)) as $file) {
            $migration = basename($file);
            DB::transaction(function () use ($current, $migration, $file): void {
                if (! SystemModuleMigration::query()->where('module', $current->name())->where('migration', $migration)->exists()) {
                    return;
                }

                $instance = require $file;

                if (! is_object($instance) || ! method_exists($instance, 'down')) {
                    throw new RuntimeException('Module rollback blocked by irreversible migration: '.$migration);
                }

                $instance->down();

                SystemModuleMigration::query()
                    ->where('module', $current->name())
                    ->where('migration', $migration)
                    ->delete();
            });
        }
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
            throw new RuntimeException('Recorded module migration file is missing: '.(string) $records->first()->migration);
        }

        $files = [];

        foreach ($records as $record) {
            $file = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$record->migration;

            if (! is_file($file)) {
                throw new RuntimeException('Recorded module migration file is missing: '.$record->migration);
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
        if ($currentPath === null || ! is_dir($currentPath)) {
            return [];
        }

        $currentMap = array_fill_keys(array_map('basename', $this->migrationFiles($currentPath)), true);
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
            if (! isset($currentMap[$migration]) || isset($targetMap[$migration])) {
                continue;
            }

            $file = rtrim($currentPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$migration;
            if (! is_file($file)) {
                throw new RuntimeException('Recorded module migration file is missing: '.$migration);
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
