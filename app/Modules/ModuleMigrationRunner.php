<?php

namespace App\Modules;

use App\Models\SystemModuleMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

            try {
                DB::transaction(function () use ($manifest, $migration, $batch, $file): void {
                    if (SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                        return;
                    }

                    $instance = require $file;

                    if (! is_object($instance) || ! method_exists($instance, 'up')) {
                        throw new RuntimeException("Module migration [{$migration}] must return an object with up().");
                    }

                    $instance->up();

                    SystemModuleMigration::query()->create([
                        'module' => $manifest->name(),
                        'migration' => $migration,
                        'batch' => $batch,
                        'ran_at' => time(),
                    ]);
                });
            } catch (QueryException $exception) {
                if ($this->isDuplicateTrackingRow($exception)) {
                    continue;
                }

                throw $exception;
            }
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

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
