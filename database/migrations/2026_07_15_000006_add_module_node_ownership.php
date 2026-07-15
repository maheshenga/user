<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_node')) {
            return;
        }

        $addOwner = ! Schema::hasColumn('system_node', 'owner_module');
        $addHash = ! Schema::hasColumn('system_node', 'managed_hash');
        $addStatus = ! Schema::hasColumn('system_node', 'status');
        Schema::table('system_node', function (Blueprint $table) use ($addOwner, $addHash, $addStatus): void {
            if ($addOwner) {
                $table->string('owner_module', 80)->default('core')->after('id');
                $table->index('owner_module', 'system_node_owner_module_index');
            }
            if ($addHash) {
                $table->string('managed_hash', 64)->nullable()->after('owner_module');
            }
            if ($addStatus) {
                $table->unsignedTinyInteger('status')->default(1)->after('is_auth');
                $table->index('status', 'system_node_status_index');
            }
        });

        if (Schema::hasTable('system_module')) {
            DB::table('system_module')
                ->select(['name', 'admin_prefix', 'status'])
                ->whereNotNull('admin_prefix')
                ->orderBy('id')
                ->each(function (object $module): void {
                    $prefix = trim((string) $module->admin_prefix);
                    if ($prefix === '') {
                        return;
                    }

                    DB::table('system_node')
                        ->where('owner_module', 'core')
                        ->where(function ($query) use ($prefix): void {
                            $query->where('node', $prefix)
                                ->orWhere('node', 'like', $prefix.'/%');
                        })
                        ->update([
                            'owner_module' => (string) $module->name,
                            'status' => in_array((string) $module->status, ['installed', 'enabled'], true) ? 1 : 0,
                        ]);
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_node')) {
            return;
        }

        Schema::table('system_node', function (Blueprint $table): void {
            if (Schema::hasColumn('system_node', 'owner_module')) {
                $table->dropIndex('system_node_owner_module_index');
            }
            if (Schema::hasColumn('system_node', 'status')) {
                $table->dropIndex('system_node_status_index');
            }
        });
        Schema::table('system_node', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['owner_module', 'managed_hash', 'status'],
                static fn (string $column): bool => Schema::hasColumn('system_node', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
