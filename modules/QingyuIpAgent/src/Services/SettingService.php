<?php

namespace Modules\QingyuIpAgent\Services;

use Modules\QingyuIpAgent\Models\QingyuIpAgentSetting;

class SettingService
{
    public function all(): array
    {
        return QingyuIpAgentSetting::query()
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (QingyuIpAgentSetting $setting): array => [$setting->key => $this->castValue($setting)])
            ->all();
    }

    public function save(array $payload): array
    {
        $allowed = [
            'module_enabled_note' => 'string',
            'default_vip_plan_id' => 'integer',
            'activation_batch_prefix' => 'string',
        ];
        $now = time();

        foreach ($allowed as $key => $type) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            QingyuIpAgentSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $this->serializeValue($payload[$key], $type),
                    'value_type' => $type,
                    'description' => $this->description($key),
                    'update_time' => $now,
                    'create_time' => QingyuIpAgentSetting::query()->where('key', $key)->value('create_time') ?: $now,
                ]
            );
        }

        return $this->all();
    }

    private function castValue(QingyuIpAgentSetting $setting): mixed
    {
        return match ($setting->value_type) {
            'integer' => (int) $setting->value,
            'boolean' => (bool) $setting->value,
            'json' => json_decode((string) $setting->value, true) ?: [],
            default => $setting->value,
        };
    }

    private function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'integer' => (string) (int) $value,
            'boolean' => (string) (int) (bool) $value,
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => trim((string) $value),
        };
    }

    private function description(string $key): string
    {
        return match ($key) {
            'default_vip_plan_id' => '默认 VIP 套餐 ID',
            'activation_batch_prefix' => '激活码批次默认前缀',
            default => '模块备注',
        };
    }
}
