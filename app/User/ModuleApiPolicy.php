<?php

namespace App\User;

use App\Models\SystemModule;
use App\Models\UserAccount;

final class ModuleApiPolicy
{
    public function assertAvailable(string $module): SystemModule
    {
        $record = SystemModule::query()->where('name', $module)->first();
        if ($record === null || $record->status !== 'enabled') {
            throw new UserApiException('模块当前未启用。', 403, 'module_unavailable');
        }

        return $record;
    }

    public function assertUserAccess(string $module, UserAccount $user): SystemModule
    {
        $record = $this->assertAvailable($module);
        if ((string) ($user->source_module ?: 'core') !== $module) {
            throw new UserApiException('账号不属于当前模块。', 403, 'module_account_mismatch');
        }

        return $record;
    }

    /**
     * @return array<int, string>
     */
    public function abilities(string $module): array
    {
        $record = $this->assertAvailable($module);
        $manifest = is_array($record->config_json) ? $record->config_json : [];
        $api = is_array($manifest['api'] ?? null) ? $manifest['api'] : [];
        $abilities = is_array($api['abilities'] ?? null) ? $api['abilities'] : [];
        $allowed = array_fill_keys((array) config('user_api.allowed_abilities', []), true);
        $abilities = array_values(array_unique(array_filter(
            $abilities,
            static fn (mixed $ability): bool => is_string($ability) && isset($allowed[$ability])
        )));

        if ($abilities === [] || ! in_array('module:'.$module, $abilities, true)) {
            throw new UserApiException('模块无权签发用户令牌。', 403, 'module_not_allowed');
        }

        return $abilities;
    }
}
