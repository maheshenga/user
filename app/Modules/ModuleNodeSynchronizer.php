<?php

namespace App\Modules;

use App\Http\Services\TriggerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleNodeSynchronizer
{
    public function __construct(
        private readonly ModuleNodeScanner $scanner,
    ) {}

    public function sync(ModuleManifest $manifest): int
    {
        $module = $manifest->name();
        $prefix = $manifest->adminPrefix().'/';
        $nodes = [];

        foreach ($this->scanner->nodesForManifest($manifest) as $node) {
            $name = (string) ($node['node'] ?? '');
            if (! str_starts_with($name.'/', $prefix)) {
                throw new InvalidArgumentException("Module [{$module}] node [{$name}] is outside its admin prefix.");
            }
            if (isset($nodes[$name]) && $nodes[$name] !== $node) {
                throw new InvalidArgumentException("Module [{$module}] declares conflicting node [{$name}].");
            }
            $nodes[$name] = $node;
        }

        $count = DB::transaction(function () use ($module, $nodes): int {
            $seen = [];
            foreach ($nodes as $name => $node) {
                $seen[] = $name;
                $payload = [
                    'title' => $node['title'] ?? null,
                    'type' => (int) ($node['type'] ?? 2),
                    'is_auth' => (int) ((bool) ($node['is_auth'] ?? false)),
                    'status' => 1,
                ];
                $managedHash = hash('sha256', json_encode(
                    ['node' => $name] + $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ));
                $existing = DB::table('system_node')->where('node', $name)->lockForUpdate()->first();

                if ($existing !== null && (string) $existing->owner_module !== $module) {
                    throw new InvalidArgumentException(
                        "Module node [{$name}] already belongs to module [{$existing->owner_module}]."
                    );
                }

                if ($existing === null) {
                    DB::table('system_node')->insert([
                        'owner_module' => $module,
                        'managed_hash' => $managedHash,
                        'node' => $name,
                        'create_time' => time(),
                        'update_time' => time(),
                    ] + $payload);
                } else {
                    DB::table('system_node')->where('id', $existing->id)->update([
                        'managed_hash' => $managedHash,
                        'update_time' => time(),
                    ] + $payload);
                }
            }

            $stale = DB::table('system_node')->where('owner_module', $module);
            if ($seen !== []) {
                $stale->whereNotIn('node', $seen);
            }
            $stale->update([
                'status' => 0,
                'update_time' => time(),
            ]);

            return count($seen);
        });

        TriggerService::updateNode();

        return $count;
    }

    public function hide(string $module): int
    {
        $count = DB::table('system_node')
            ->where('owner_module', $module)
            ->where('status', 1)
            ->update([
                'status' => 0,
                'update_time' => time(),
            ]);

        if ($count > 0) {
            TriggerService::updateNode();
        }

        return $count;
    }
}
