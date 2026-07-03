<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use Illuminate\Support\Facades\Schema;

final class ModuleRepository
{
    public function upsertDiscovered(ModuleManifest $manifest): void
    {
        SystemModule::query()->updateOrCreate(
            ['name' => $manifest->name()],
            [
                'title' => $manifest->title(),
                'vendor' => $manifest->vendor(),
                'version' => $manifest->version(),
                'type' => $manifest->type(),
                'trust_level' => $manifest->type(),
                'status' => SystemModule::query()->where('name', $manifest->name())->value('status') ?: 'discovered',
                'path' => $manifest->path(),
                'namespace' => $manifest->namespace(),
                'admin_prefix' => $manifest->adminPrefix(),
                'config_json' => $manifest->toArray(),
                'update_time' => time(),
            ]
        );
    }

    public function installed(string $name): ?SystemModule
    {
        return SystemModule::query()->where('name', $name)->first();
    }

    /**
     * @return iterable<SystemModule>
     */
    public function enabled(): iterable
    {
        return SystemModule::query()
            ->where('status', 'enabled')
            ->get();
    }

    public function enabledByPrefix(string $adminPrefix): ?SystemModule
    {
        return SystemModule::query()
            ->where('admin_prefix', $adminPrefix)
            ->where('status', 'enabled')
            ->first();
    }

    public function setStatus(string $name, string $status, ?string $error = null): SystemModule
    {
        $module = SystemModule::query()->where('name', $name)->firstOrFail();
        $now = time();
        $payload = ['status' => $status, 'last_error' => $error, 'update_time' => $now];
        if ($status === 'enabled') {
            $payload['enabled_at'] = $now;
        }
        if ($status === 'disabled') {
            $payload['disabled_at'] = $now;
        }
        if ($status === 'installed' && empty($module->installed_at)) {
            $payload['installed_at'] = $now;
        }
        $module->update($payload);

        return $module->refresh();
    }

    public function log(string $action, string $name, ?string $oldState, ?string $newState, string $result, ?string $error = null, ?int $actorId = null): void
    {
        if (! Schema::hasTable('system_module_log')) {
            return;
        }

        SystemModuleLog::query()->create([
            'admin_id' => $actorId,
            'module' => $name,
            'action' => $action,
            'old_state' => $oldState,
            'new_state' => $newState,
            'started_at' => time(),
            'finished_at' => time(),
            'result' => $result,
            'error_message' => $error,
        ]);
    }

    public function setLastError(string $name, ?string $error): void
    {
        if (! Schema::hasTable('system_module')) {
            return;
        }

        SystemModule::query()->where('name', $name)->update([
            'last_error' => $error,
            'update_time' => time(),
        ]);
    }
}
