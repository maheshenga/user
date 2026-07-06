<?php

namespace App\Modules;

use RuntimeException;

final class ModuleFileStore
{
    public function backup(string $source, string $module, string $version): string
    {
        if (! is_dir($source)) {
            throw new RuntimeException("模块目录不存在：{$source}");
        }

        $target = $this->uniqueBackupTarget($module, $version);

        try {
            $this->copyDirectory($source, $target);
        } catch (RuntimeException $exception) {
            $this->deleteDirectoryUnchecked($target);

            throw $exception;
        }

        return $target;
    }

    public function replace(string $target, string $source): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("替换目录不存在：{$source}");
        }

        if ($this->hasDotSegments($target)) {
            throw new RuntimeException('替换目标包含点号路径段。');
        }

        if ($this->hasDotSegments($source)) {
            throw new RuntimeException('替换源目录包含点号路径段。');
        }

        $normalizedTarget = $this->normalizePath($target);
        $normalizedSource = $this->normalizePath($source);

        if ($normalizedTarget === $normalizedSource) {
            throw new RuntimeException('替换目标必须不同于源目录。');
        }

        if (
            $this->isWithinRoot($normalizedTarget, $normalizedSource)
            || $this->isWithinRoot($normalizedSource, $normalizedTarget)
        ) {
            throw new RuntimeException('替换目标不能包含源目录，也不能位于源目录内。');
        }

        $modulesRoot = $this->modulesRoot();
        if (! $this->isWithinRoot($normalizedTarget, $modulesRoot)) {
            throw new RuntimeException('替换目标不在允许的模块目录内。');
        }

        $this->assertNoSymlinkAncestors($normalizedTarget, $modulesRoot);

        $backupPath = null;
        $cleanupBackup = false;
        if (file_exists($target) || is_link($target)) {
            $backupPath = storage_path('modules/tmp/'.uniqid('replace_backup_', true));

            try {
                $this->copyDirectory($target, $backupPath);
            } catch (RuntimeException $exception) {
                $this->deleteDirectoryUnchecked($backupPath);

                throw $exception;
            }
        }

        try {
            $this->deleteDirectoryUnchecked($target);
            $this->copyDirectory($source, $target);
            $cleanupBackup = true;
        } catch (RuntimeException $exception) {
            $this->deleteDirectoryUnchecked($target);

            if ($backupPath !== null && is_dir($backupPath)) {
                try {
                    $this->copyDirectory($backupPath, $target);
                    $cleanupBackup = true;
                } catch (RuntimeException) {
                    $cleanupBackup = false;
                }
            }

            throw $exception;
        } finally {
            if ($backupPath !== null && $cleanupBackup) {
                $this->deleteDirectory($backupPath);
            }
        }
    }

    public function copyToTemp(string $source, string $prefix): string
    {
        $target = storage_path('modules/tmp/'.uniqid($this->safeSegment($prefix), true));

        try {
            $this->copyDirectory($source, $target);
        } catch (RuntimeException $exception) {
            $this->deleteDirectoryUnchecked($target);

            throw $exception;
        }

        return $target;
    }

    public function deleteDirectory(string $path): void
    {
        if ($this->hasDotSegments($path)) {
            throw new RuntimeException('删除路径包含点号路径段。');
        }

        $normalizedPath = $this->normalizePath($path);
        $root = $this->allowedDeleteRoot($normalizedPath);
        if ($root === null) {
            throw new RuntimeException('删除路径不在允许的模块目录内。');
        }

        if ($normalizedPath === $root) {
            throw new RuntimeException('删除路径不能是安全根目录。');
        }

        $this->assertNoSymlinkAncestors($normalizedPath, $root);
        $this->deleteDirectoryUnchecked($path);
    }

    private function deleteDirectoryUnchecked(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            $this->deletePathUnchecked($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($child) && ! is_link($child)) {
                $this->deleteDirectoryUnchecked($child);
                continue;
            }

            $this->deletePathUnchecked($child);
        }

        if (! @rmdir($path)) {
            throw new RuntimeException("无法删除目录：{$path}");
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (is_link($source) || is_file($source)) {
            throw new RuntimeException("期望目录，实际是文件：{$source}");
        }

        if (! is_dir($source)) {
            throw new RuntimeException("源目录不存在：{$source}");
        }

        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException("目标目录已存在：{$target}");
        }

        if (! mkdir($target, 0777, true) && ! is_dir($target)) {
            throw new RuntimeException("无法创建目录：{$target}");
        }

        $entries = scandir($source);
        if ($entries === false) {
            throw new RuntimeException("无法读取目录：{$source}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.DIRECTORY_SEPARATOR.$entry;
            $to = $target.DIRECTORY_SEPARATOR.$entry;

            if (is_link($from)) {
                throw new RuntimeException("拒绝复制符号链接：{$from}");
            }

            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }

            if (! copy($from, $to)) {
                throw new RuntimeException("无法复制文件：{$from}");
            }
        }
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';

        return in_array($safe, ['', '.', '..'], true) ? '_' : $safe;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = strtolower(substr($path, 0, 2));
            $path = substr($path, 2);
        }

        $absolute = str_starts_with($path, '/');
        $segments = preg_split('#/+#', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                if ($normalized !== [] && end($normalized) !== '..') {
                    array_pop($normalized);
                    continue;
                }
            }

            $normalized[] = $segment;
        }

        $normalizedPath = implode('/', $normalized);

        if ($absolute) {
            $normalizedPath = '/'.$normalizedPath;
        }

        return $prefix.$normalizedPath;
    }

    private function deletePathUnchecked(string $path): void
    {
        if (@unlink($path) || @rmdir($path)) {
            return;
        }

        throw new RuntimeException("无法删除路径：{$path}");
    }

    private function assertNoSymlinkAncestors(string $target, string $root): void
    {
        $root = rtrim($root, '/');
        $target = str_replace('\\', '/', $target);

        if (is_link($root)) {
            throw new RuntimeException('替换目标包含符号链接父目录。');
        }

        $parent = dirname($target);
        if ($parent === '.' || $parent === '') {
            return;
        }

        $relative = ltrim(substr($parent, strlen($root)), '/');
        if ($relative === '') {
            return;
        }

        $current = $root;
        foreach (preg_split('#/+#', $relative, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            $current .= '/'.$segment;

            if (! file_exists($current) && ! is_link($current)) {
                break;
            }

            if (is_link($current)) {
                throw new RuntimeException('替换目标包含符号链接父目录。');
            }
        }
    }

    private function hasDotSegments(string $path): bool
    {
        foreach (preg_split('#[\\\\/]+#', str_replace('\\', '/', $path), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function allowedDeleteRoot(string $path): ?string
    {
        foreach ([
            $this->modulesRoot(),
            $this->normalizePath(storage_path('modules/tmp')),
            $this->normalizePath(storage_path('modules/backups')),
        ] as $root) {
            if ($this->isWithinRoot($path, $root)) {
                return $root;
            }
        }

        return null;
    }

    private function modulesRoot(): string
    {
        return $this->normalizePath((string) config('modules.path'));
    }

    private function uniqueBackupTarget(string $module, string $version): string
    {
        $root = storage_path('modules/backups/'.$this->safeSegment($module));
        $prefix = date('YmdHis').'-'.$this->safeSegment($version).'-';

        do {
            $target = $root.DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(4));
        } while (file_exists($target) || is_link($target));

        return $target;
    }
}
