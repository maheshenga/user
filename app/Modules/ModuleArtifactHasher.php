<?php

namespace App\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ModuleArtifactHasher
{
    public function hashDirectory(string $path): string
    {
        $root = realpath($path);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException("模块制品目录不存在：{$path}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isLink()) {
                throw new RuntimeException('模块制品不能包含符号链接：'.$file->getPathname());
            }
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = $file->getPathname();
        }

        ksort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $relative => $file) {
            hash_update($context, $relative."\0".hash_file('sha256', $file)."\0");
        }

        return hash_final($context);
    }
}
