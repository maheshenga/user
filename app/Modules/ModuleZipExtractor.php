<?php

namespace App\Modules;

use RuntimeException;
use ZipArchive;

final class ModuleZipExtractor
{
    public function extract(string $zipPath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required.');
        }

        $target = storage_path('modules/tmp/'.uniqid('module_', true));
        if (! mkdir($target, 0777, true) && ! is_dir($target)) {
            throw new RuntimeException("Unable to create extraction directory: {$target}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->cleanup($target);
            throw new RuntimeException('Unable to open module zip.');
        }

        try {
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

                    if ($this->isUnsafeEntry($zip, $index, $name)) {
                        throw new RuntimeException("unsafe zip entry: {$name}");
                    }
                }

                if (! $zip->extractTo($target)) {
                    throw new RuntimeException('Unable to extract module zip.');
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

        throw new RuntimeException('module.json not found in module zip.');
    }

    private function cleanup(string $path): void
    {
        (new ModuleFileStore())->deleteDirectory($path);
    }
}
