<?php

namespace App\Modules;

use RuntimeException;
use ZipArchive;

final class ModuleZipExtractor
{
    private const MAX_ENTRIES = 1000;
    private const MAX_ENTRY_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public function extract(string $zipPath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive 扩展不可用。');
        }

        $target = storage_path('modules/tmp/'.uniqid('module_', true));
        if (! mkdir($target, 0777, true) && ! is_dir($target)) {
            throw new RuntimeException("无法创建模块解压目录：{$target}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->cleanup($target);
            throw new RuntimeException('无法打开模块 zip 包。');
        }

        try {
            try {
                $totalUncompressedBytes = 0;

                for ($index = 0; $index < $zip->numFiles; $index++) {
                    if ($index >= self::MAX_ENTRIES) {
                        throw new RuntimeException('模块 zip 包过大：条目数量超过限制。');
                    }

                    $rawName = $zip->getNameIndex($index);
                    if ($rawName === false) {
                        throw new RuntimeException("模块 zip 包包含不安全条目：index {$index}");
                    }

                    $name = str_replace('\\', '/', $rawName);
                    $totalUncompressedBytes += $this->entryUncompressedSize($zip, $index, $name);

                    if ($totalUncompressedBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                        throw new RuntimeException('模块 zip 包过大：解压后总大小超过限制。');
                    }

                    if ($this->isUnsafeEntry($zip, $index, $name)) {
                        throw new RuntimeException("模块 zip 包包含不安全条目：{$name}");
                    }
                }

                if (! $zip->extractTo($target)) {
                    throw new RuntimeException('无法解压模块 zip 包。');
                }
            } finally {
                $zip->close();
            }

            return $this->moduleRoot($target);
        } catch (RuntimeException $exception) {
            $this->cleanup($target);
            throw $exception;
        }
    }

    private function entryUncompressedSize(ZipArchive $zip, int $index, string $name): int
    {
        $stat = $zip->statIndex($index);
        if (! is_array($stat) || ! isset($stat['size'])) {
            throw new RuntimeException("模块 zip 包包含不安全条目：{$name}");
        }

        $size = (int) $stat['size'];
        if ($size > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('模块 zip 包过大：单个条目解压后大小超过限制。');
        }

        return $size;
    }

    private function isUnsafeEntry(ZipArchive $zip, int $index, string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/')) {
            return true;
        }

        if (preg_match('/^[A-Za-z]:[\\/]/', $name) === 1) {
            return true;
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        if (! method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $attributes = 0;
        $opsys = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0170000) === 0120000;
    }

    private function moduleRoot(string $target): string
    {
        $manifest = $target.DIRECTORY_SEPARATOR.'module.json';
        if (is_file($manifest)) {
            return $target;
        }

        $children = array_values(array_filter(
            scandir($target) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));

        if (
            count($children) === 1
            && is_dir($target.DIRECTORY_SEPARATOR.$children[0])
            && ! is_link($target.DIRECTORY_SEPARATOR.$children[0])
            && is_file($target.DIRECTORY_SEPARATOR.$children[0].DIRECTORY_SEPARATOR.'module.json')
        ) {
            return $target.DIRECTORY_SEPARATOR.$children[0];
        }

        throw new RuntimeException('模块 zip 包中未找到 module.json。');
    }

    private function cleanup(string $path): void
    {
        (new ModuleFileStore())->deleteDirectory($path);
    }
}
