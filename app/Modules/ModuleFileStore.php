<?php

namespace App\Modules;

use RuntimeException;

final class ModuleFileStore
{
    public function backup(string $source, string $module, string $version): string
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Module directory not found: {$source}");
        }

        $target = storage_path('modules/backups/'.$this->safeSegment($module).'/'.$this->safeSegment($version).'-'.date('YmdHis'));
        $this->copyDirectory($source, $target);

        return $target;
    }

    public function replace(string $target, string $source): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Replacement directory not found: {$source}");
        }

        $normalizedTarget = $this->normalizePath($target);
        $normalizedSource = $this->normalizePath($source);

        if ($normalizedTarget === $normalizedSource) {
            throw new RuntimeException('Replacement target must differ from source.');
        }

        if (! $this->isAllowedTarget($normalizedTarget)) {
            throw new RuntimeException('Replacement target is outside allowed roots.');
        }

        $backupPath = null;
        if (file_exists($target) || is_link($target)) {
            $backupPath = storage_path('modules/tmp/'.uniqid('replace_backup_', true));
            $this->copyDirectory($target, $backupPath);
        }

        try {
            $this->deleteDirectory($target);
            $this->copyDirectory($source, $target);
        } catch (RuntimeException $exception) {
            $this->deleteDirectory($target);

            if ($backupPath !== null && is_dir($backupPath)) {
                $this->copyDirectory($backupPath, $target);
            }

            throw $exception;
        } finally {
            if ($backupPath !== null) {
                $this->deleteDirectory($backupPath);
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            $this->deleteLeaf($path);

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
                $this->deleteDirectory($child);
                continue;
            }

            $this->deleteLeaf($child);
        }

        if (! @rmdir($path)) {
            throw new RuntimeException("Unable to remove directory: {$path}");
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (is_link($source) || is_file($source)) {
            throw new RuntimeException("Expected directory, got file: {$source}");
        }

        if (! is_dir($source)) {
            throw new RuntimeException("Source directory not found: {$source}");
        }

        if (! is_dir($target)) {
            if (! mkdir($target, 0777, true) && ! is_dir($target)) {
                throw new RuntimeException("Unable to create directory: {$target}");
            }
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.DIRECTORY_SEPARATOR.$entry;
            $to = $target.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($from) && ! is_link($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }

            if (! copy($from, $to)) {
                throw new RuntimeException("Unable to copy file: {$from}");
            }
        }
    }

    private function isAllowedTarget(string $target): bool
    {
        $roots = [
            $this->normalizePath((string) config('modules.path')),
            $this->normalizePath(storage_path()),
        ];

        foreach ($roots as $root) {
            if ($target === $root || str_starts_with($target, rtrim($root, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function safeSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';
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

    private function deleteLeaf(string $path): void
    {
        if (@unlink($path) || @rmdir($path)) {
            return;
        }

        throw new RuntimeException("Unable to delete path: {$path}");
    }
}
