<?php

namespace App\Modules;

final class ModuleAutoloader
{
    /**
     * @var array<string, bool>
     */
    private static array $registered = [];

    public function register(ModuleManifest $manifest): void
    {
        $namespace = trim($manifest->namespace(), '\\').'\\';
        $sourceRoot = $manifest->path().DIRECTORY_SEPARATOR.'src';
        $sourceRootReal = realpath($sourceRoot);

        if ($sourceRootReal === false) {
            return;
        }

        $key = $namespace.'|'.$sourceRootReal;
        if (isset(self::$registered[$key])) {
            return;
        }

        self::$registered[$key] = true;

        spl_autoload_register(static function (string $class) use ($namespace, $sourceRootReal): void {
            if (! str_starts_with($class, $namespace)) {
                return;
            }

            $relativeClass = substr($class, strlen($namespace));
            if ($relativeClass === '' || preg_match('/[^A-Za-z0-9_\\\\]/', $relativeClass) === 1) {
                return;
            }

            $file = $sourceRootReal.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass).'.php';
            $fileReal = realpath($file);

            if ($fileReal !== false && self::isPathWithin($sourceRootReal, $fileReal)) {
                require_once $fileReal;
            }
        });
    }

    private static function isPathWithin(string $root, string $path): bool
    {
        $root = str_replace('\\', '/', $root);
        $path = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Za-z]:/', $root) === 1) {
            $root = strtolower($root);
            $path = strtolower($path);
        }

        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }
}
