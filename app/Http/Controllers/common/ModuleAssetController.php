<?php

namespace App\Http\Controllers\common;

use App\Modules\ModuleManager;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModuleAssetController extends Controller
{
    public function show(string $module, string $path, ModuleManager $manager): BinaryFileResponse|Response
    {
        $manifest = $manager->enabledByPrefix($module);
        abort_if($manifest === null, 404);
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);

        $assetRoot = realpath($manifest->assetsPath());
        $file = $manifest->assetsPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $filePath = realpath($file);

        abort_if($assetRoot === false || $filePath === false || ! is_file($filePath), 404);
        abort_if(! $this->isWithinRoot($assetRoot, $filePath), 404);

        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'js') {
            return response(file_get_contents($filePath), 200, [
                'Content-Type' => 'text/javascript; charset=UTF-8',
            ]);
        }

        return response()->file($filePath);
    }

    private function isWithinRoot(string $assetRoot, string $filePath): bool
    {
        $normalizedRoot = $this->normalizePath($assetRoot);
        $normalizedFile = $this->normalizePath($filePath);

        return $normalizedFile === $normalizedRoot
            || str_starts_with($normalizedFile, $normalizedRoot.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
