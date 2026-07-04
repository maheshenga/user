<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ModuleRollbacker
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleFileStore $files,
        private readonly ModuleMigrationRunner $migrations,
    ) {}

    public function rollback(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $status = (string) $module->status;

        try {
            $backup = $this->latestBackup($name);
            ModuleManifest::fromFile($backup.DIRECTORY_SEPARATOR.'module.json');

            $currentPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR);
            $current = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');

            $this->migrations->assertReversible($current);
            $this->migrations->rollbackRecorded($current);
            $this->files->replace($currentPath, $backup);

            $restored = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->repository->restoreVersion($restored, $status);
            $this->repository->log('rollback', $name, $status, $status, 'success', null, $actorId);
        } catch (Throwable $exception) {
            $this->repository->setLastError($name, $exception->getMessage());
            $this->repository->log('rollback', $name, $status, $status, 'failed', $exception->getMessage(), $actorId);

            throw $exception;
        }

        $this->clearCaches();
    }

    private function latestBackup(string $name): string
    {
        $root = storage_path('modules/backups/'.$name);
        $entries = is_dir($root)
            ? array_values(array_filter(scandir($root) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'))
            : [];
        rsort($entries);

        foreach ($entries as $entry) {
            $path = $root.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && is_file($path.DIRECTORY_SEPARATOR.'module.json')) {
                return $path;
            }
        }

        throw new RuntimeException("No backup found for module: {$name}");
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
