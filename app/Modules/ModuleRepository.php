<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Models\SystemModuleVersion;
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
                'status' => SystemModule::query()->where('name', $manifest->name())->value('status') ?: 'pending_review',
                'path' => $manifest->path(),
                'namespace' => $manifest->namespace(),
                'admin_prefix' => $manifest->adminPrefix(),
                'config_json' => $manifest->toArray(),
                'update_time' => time(),
            ]
        );
    }

    public function updateFromManifest(ModuleManifest $manifest, string $status): void
    {
        SystemModule::query()->where('name', $manifest->name())->update([
            'title' => $manifest->title(),
            'vendor' => $manifest->vendor(),
            'version' => $manifest->version(),
            'type' => $manifest->type(),
            'trust_level' => $manifest->type(),
            'status' => $status,
            'path' => $manifest->path(),
            'namespace' => $manifest->namespace(),
            'admin_prefix' => $manifest->adminPrefix(),
            'config_json' => $manifest->toArray(),
            'last_error' => null,
            'update_time' => time(),
        ]);
    }

    public function restoreVersion(ModuleManifest $manifest, string $status): void
    {
        $version = SystemModuleVersion::query()
            ->where('module', $manifest->name())
            ->where('version', $manifest->version())
            ->first();

        if ($version === null) {
            $this->updateFromManifest($manifest, $status);

            return;
        }

        $data = $version->manifest_json;
        SystemModule::query()->where('name', $manifest->name())->update([
            'title' => (string) ($data['title'] ?? $manifest->title()),
            'vendor' => (string) ($data['vendor'] ?? $manifest->vendor()),
            'version' => (string) ($data['version'] ?? $manifest->version()),
            'type' => (string) ($data['type'] ?? $manifest->type()),
            'trust_level' => (string) ($data['type'] ?? $manifest->type()),
            'status' => $status,
            'path' => $manifest->path(),
            'namespace' => (string) ($data['namespace'] ?? $manifest->namespace()),
            'admin_prefix' => (string) ($data['admin_prefix'] ?? $manifest->adminPrefix()),
            'config_json' => $data,
            'last_error' => null,
            'update_time' => time(),
        ]);
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

    public function approve(string $name, ?int $actorId = null): void
    {
        $module = SystemModule::query()->where('name', $name)->firstOrFail();
        $oldState = (string) $module->status;
        if (! in_array($oldState, ['pending_review', 'rejected'], true)) {
            throw new \InvalidArgumentException("模块 [{$name}] 当前状态 [{$oldState}] 不允许审核通过。");
        }

        $module->update([
            'status' => 'approved',
            'last_error' => null,
            'update_time' => time(),
        ]);
        $this->log('approve', $name, $oldState, 'approved', 'success', null, $actorId);
    }

    public function reject(string $name, string $reason, ?int $actorId = null): void
    {
        $module = SystemModule::query()->where('name', $name)->firstOrFail();
        $oldState = (string) $module->status;
        if (! in_array($oldState, ['pending_review', 'approved'], true)) {
            throw new \InvalidArgumentException("模块 [{$name}] 当前状态 [{$oldState}] 不允许审核拒绝。");
        }

        $module->update([
            'status' => 'rejected',
            'last_error' => $reason,
            'update_time' => time(),
        ]);
        $this->log('reject', $name, $oldState, 'rejected', 'success', $reason, $actorId);
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
