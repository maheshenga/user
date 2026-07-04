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
        $restoreSource = null;

        try {
            $backup = $this->latestBackup($name);
            $target = ModuleManifest::fromFile($backup.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($target, $name);

            $currentPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR);
            $current = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($current, $name);
            $restoreSource = $this->files->copyToTemp($backup, 'rollback_restore_');

            $this->migrations->assertMissingReversible($current, $target);
            $this->migrations->rollbackMissingFrom($current, $target);
            $this->files->replace($currentPath, $restoreSource);

            $restored = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->repository->restoreVersion($restored, $status);
            $this->repository->log('rollback', $name, $status, $status, 'success', null, $actorId);
        } catch (Throwable $exception) {
            $this->repository->setLastError($name, $exception->getMessage());
            $this->repository->log('rollback', $name, $status, $status, 'failed', $exception->getMessage(), $actorId);

            throw $exception;
        } finally {
            if ($restoreSource !== null && is_dir($restoreSource)) {
                try {
                    $this->files->deleteDirectory($restoreSource);
                } catch (Throwable) {
                }
            }
        }

        $this->clearCaches();
    }

    private function latestBackup(string $name): string
    {
        $root = storage_path('modules/backups/'.$name);
        $entries = is_dir($root)
            ? array_values(array_filter(scandir($root) ?: [], static function (string $entry) use ($root): bool {
                $path = $root.DIRECTORY_SEPARATOR.$entry;

                return $entry !== '.' && $entry !== '..' && is_dir($path) && is_file($path.DIRECTORY_SEPARATOR.'module.json');
            }))
            : [];
        usort($entries, static function (string $left, string $right) use ($root): int {
            $leftTime = filemtime($root.DIRECTORY_SEPARATOR.$left) ?: 0;
            $rightTime = filemtime($root.DIRECTORY_SEPARATOR.$right) ?: 0;

            return ($rightTime <=> $leftTime) ?: strcmp($right, $left);
        });

        foreach ($entries as $entry) {
            $path = $root.DIRECTORY_SEPARATOR.$entry;

            return $path;
        }

        throw new RuntimeException("No backup found for module: {$name}");
    }

    private function assertManifestName(ModuleManifest $manifest, string $expectedName): void
    {
        if ($manifest->name() !== $expectedName) {
            throw new InvalidArgumentException("Expected module [{$expectedName}], got [{$manifest->name()}].");
        }
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
