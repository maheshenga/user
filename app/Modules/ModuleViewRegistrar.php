<?php

namespace App\Modules;

use Illuminate\Support\Facades\View;

final class ModuleViewRegistrar
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function registerEnabled(): void
    {
        foreach ($this->modules->enabled() as $manifest) {
            if (is_dir($manifest->viewsPath())) {
                View::addNamespace('modules.'.$manifest->adminPrefix(), $manifest->viewsPath());
            }
        }
    }
}
