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

        $target = storage_path('modules/backups/'.$module.'/'.$version.'-'.date('YmdHis'));
        $this->copyDirectory($source, $target);

        return $target;
    }

    public function replace(string $target, string $source): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Replacement directory not found: {$source}");
        }

        $this->deleteDirectory($target);
        $this->copyDirectory($source, $target);
    }

    public function deleteDirectory(string $path): void
    {
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

            @unlink($child);
        }

        @rmdir($path);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (! is_dir($target)) {
            mkdir($target, 0777, true);
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

            copy($from, $to);
        }
    }
}
