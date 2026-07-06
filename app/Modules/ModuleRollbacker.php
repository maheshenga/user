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
        $this->withModuleLock($name, function () use ($name, $actorId): void {
            $this->rollbackLocked($name, $actorId);
        });
    }

    private function rollbackLocked(string $name, ?int $actorId): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("模块未安装：{$name}");
        }

        $status = (string) $module->status;
        if (! in_array($status, ['installed', 'enabled', 'disabled'], true)) {
            throw new InvalidArgumentException("模块 [{$name}] 当前状态 [{$status}] 不允许回滚。");
        }

        $restoreSource = null;
        $currentSource = null;
        $currentReplaced = false;
        $keepCurrentSource = false;

        try {
            $backup = $this->latestBackup($name);
            $target = ModuleManifest::fromFile($backup.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($target, $name);

            $currentPath = rtrim((string) $module->path, DIRECTORY_SEPARATOR);
            $current = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($current, $name);
            $restoreSource = $this->files->copyToTemp($backup, 'rollback_restore_');
            $currentSource = $this->files->copyToTemp($currentPath, 'rollback_current_');
            $rollbackCurrent = ModuleManifest::fromFile($currentSource.DIRECTORY_SEPARATOR.'module.json');
            $this->assertManifestName($rollbackCurrent, $name);

            $this->migrations->assertMissingReversible($rollbackCurrent, $target);
            if ($this->migrations->missingMigrationCount($rollbackCurrent, $target) > 1) {
                throw new RuntimeException('需要人工回滚：自动回滚最多支持一个缺失迁移。');
            }

            $this->migrations->rollbackMissingFrom($rollbackCurrent, $target);

            try {
                $this->files->replace($currentPath, $restoreSource);
            } catch (Throwable $exception) {
                $keepCurrentSource = true;

                throw new RuntimeException(
                    "迁移回滚后替换模块文件失败；当前文件已保留在 [{$currentSource}]：{$exception->getMessage()}",
                    0,
                    $exception
                );
            }
            $currentReplaced = true;
            $keepCurrentSource = true;

            $restored = ModuleManifest::fromFile($currentPath.DIRECTORY_SEPARATOR.'module.json');
            $this->repository->restoreVersion($restored, $status);
            $this->repository->log('rollback', $name, $status, $status, 'success', null, $actorId);
            $this->clearCaches();
            $keepCurrentSource = false;
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
            if (! $keepCurrentSource && $currentSource !== null && is_dir($currentSource)) {
                try {
                    $this->files->deleteDirectory($currentSource);
                } catch (Throwable) {
                }
            }
        }
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
            preg_match('/^(\d{14})-/', $left, $leftMatches);
            preg_match('/^(\d{14})-/', $right, $rightMatches);
            $leftTimestamp = (int) ($leftMatches[1] ?? 0);
            $rightTimestamp = (int) ($rightMatches[1] ?? 0);
            $leftTime = filemtime($root.DIRECTORY_SEPARATOR.$left) ?: 0;
            $rightTime = filemtime($root.DIRECTORY_SEPARATOR.$right) ?: 0;

            return ($rightTimestamp <=> $leftTimestamp)
                ?: ($rightTime <=> $leftTime)
                ?: strcmp($right, $left);
        });

        foreach ($entries as $entry) {
            $path = $root.DIRECTORY_SEPARATOR.$entry;

            return $path;
        }

        throw new RuntimeException("未找到模块备份：{$name}");
    }

    private function assertManifestName(ModuleManifest $manifest, string $expectedName): void
    {
        if ($manifest->name() !== $expectedName) {
            throw new InvalidArgumentException("期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。");
        }
    }

    private function clearCaches(): void
    {
        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }

    /**
     * @param  callable(): void  $operation
     */
    private function withModuleLock(string $module, callable $operation): void
    {
        $dir = storage_path('modules/locks');
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("无法创建模块锁目录：{$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.$this->safeLockSegment($module).'.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("无法打开模块锁：{$path}");
        }

        try {
            $deadline = microtime(true) + 2.0;
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        $operation();
                    } finally {
                        flock($handle, LOCK_UN);
                    }

                    return;
                }

                usleep(50_000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException("模块 [{$module}] 正在升级中，请稍后再试。");
        } finally {
            fclose($handle);
        }
    }

    private function safeLockSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';

        return in_array($safe, ['', '.', '..'], true) ? '_' : $safe;
    }
}
