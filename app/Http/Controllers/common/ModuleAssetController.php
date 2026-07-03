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

        $file = $manifest->assetsPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        abort_if(! is_file($file), 404);

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'js') {
            return response(file_get_contents($file), 200, [
                'Content-Type' => 'text/javascript; charset=UTF-8',
            ]);
        }

        return response()->file($file);
    }
}
