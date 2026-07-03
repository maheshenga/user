<?php

namespace App\Modules;

use App\Models\SystemModuleVersion;

final class ModuleVersionRecorder
{
    public function record(ModuleManifest $manifest, ?int $installedAt = null): void
    {
        SystemModuleVersion::query()->firstOrCreate(
            ['module' => $manifest->name(), 'version' => $manifest->version()],
            [
                'manifest_json' => $manifest->toArray(),
                'installed_at' => $installedAt ?? time(),
                'create_time' => time(),
            ]
        );
    }
}
