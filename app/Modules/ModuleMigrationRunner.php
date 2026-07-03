<?php

namespace App\Modules;

use App\Models\SystemModuleMigration;
use RuntimeException;

final class ModuleMigrationRunner
{
    public function runPending(ModuleManifest $manifest): void
    {
        $path = $manifest->migrationsPath();

        if ($path === null || ! is_dir($path)) {
            return;
        }

        foreach ($this->migrationFiles($path) as $file) {
            $migration = basename($file);

            if (SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                continue;
            }

            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'up')) {
                throw new RuntimeException("Module migration [{$migration}] must return an object with up().");
            }

            $instance->up();

            SystemModuleMigration::query()->create([
                'module' => $manifest->name(),
                'migration' => $migration,
                'batch' => $this->nextBatch($manifest->name()),
                'ran_at' => time(),
            ]);
        }
    }

    public function assertReversible(ModuleManifest $manifest): void
    {
        foreach ($this->recordedFiles($manifest) as $file) {
            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('Module rollback blocked by irreversible migration: '.basename($file));
            }
        }
    }

    public function rollbackRecorded(ModuleManifest $manifest): void
    {
        foreach (array_reverse($this->recordedFiles($manifest)) as $file) {
            $migration = basename($file);
            $instance = require $file;

            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('Module rollback blocked by irreversible migration: '.$migration);
            }

            $instance->down();

            SystemModuleMigration::query()
                ->where('module', $manifest->name())
                ->where('migration', $migration)
                ->delete();
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
    private function recordedFiles(ModuleManifest $manifest): array
    {
        $path = $manifest->migrationsPath();

        if ($path === null || ! is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (SystemModuleMigration::query()->where('module', $manifest->name())->orderBy('id')->get() as $record) {
            $file = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$record->migration;

            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
