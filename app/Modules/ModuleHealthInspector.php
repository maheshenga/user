<?php

namespace App\Modules;

use App\Models\SystemModule;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ModuleHealthInspector
{
    private const REQUIRED_TABLES = [
        'system_module',
        'system_module_release',
        'system_module_menu',
        'system_module_operation',
        'module_api_request',
        'user_api_sessions',
        'user_api_refresh_tokens',
        'user_module_membership',
    ];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @return array{ok: bool, issues: list<array<string, mixed>>, metrics: array<string, int>}
     */
    public function inspect(): array
    {
        $issues = [];
        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
                $issues[] = [
                    'code' => 'table_missing',
                    'table' => $table,
                    'message' => "缺少模块平台数据表：{$table}",
                ];
            }
        }

        $enabled = collect();
        $verified = [];
        if (! in_array('system_module', $missingTables, true)) {
            $enabled = SystemModule::query()->where('status', 'enabled')->orderBy('name')->get();
            foreach ($enabled as $module) {
                if ($module->active_release_id === null) {
                    $issues[] = [
                        'code' => 'active_release_missing',
                        'module' => (string) $module->name,
                        'message' => "已启用模块未绑定不可变制品：{$module->name}",
                    ];
                }
            }

            if (! in_array('system_module_release', $missingTables, true)) {
                try {
                    $verified = $this->modules->enabled(true);
                    foreach ($enabled as $module) {
                        if ($module->active_release_id !== null && ! array_key_exists((string) $module->name, $verified)) {
                            $issues[] = [
                                'code' => 'module_verification_failed',
                                'module' => (string) $module->name,
                                'message' => "模块签名、完整性或加载检查失败：{$module->name}",
                            ];
                        }
                    }
                } catch (Throwable $exception) {
                    $issues[] = [
                        'code' => 'module_inspection_failed',
                        'message' => '模块加载巡检失败：'.$exception->getMessage(),
                    ];
                }
            }

            if (
                $enabled->contains('name', 'qingyu_ip_agent')
                && (! Schema::hasColumn('activation_code_batch', 'owner_module')
                    || ! Schema::hasColumn('activation_code_redemption', 'owner_module'))
            ) {
                $issues[] = [
                    'code' => 'qingyu_ownership_columns_missing',
                    'module' => 'qingyu_ip_agent',
                    'message' => '轻语模块数据归属字段未安装。',
                ];
            }
        }

        if (app()->environment('production')) {
            $activeKeyId = trim((string) config('modules.signing_active_key_id', ''));
            $keys = (array) config('modules.signing_keys', []);
            if ($activeKeyId === '') {
                $issues[] = [
                    'code' => 'active_signing_key_missing',
                    'message' => '生产环境未配置模块活动签名密钥 ID。',
                ];
            } elseif (! isset($keys[$activeKeyId]) || ! is_string($keys[$activeKeyId]) || strlen(trim($keys[$activeKeyId])) < 32) {
                $issues[] = [
                    'code' => 'active_signing_key_invalid',
                    'key_id' => $activeKeyId,
                    'message' => '生产环境模块活动签名密钥不存在或长度不足。',
                ];
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'metrics' => [
                'required_tables' => count(self::REQUIRED_TABLES),
                'missing_tables' => count($missingTables),
                'enabled_modules' => $enabled->count(),
                'verified_modules' => count($verified),
                'issue_count' => count($issues),
            ],
        ];
    }
}
