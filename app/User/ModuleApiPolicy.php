<?php

namespace App\User;

use App\Models\SystemModule;
use App\Models\UserAccount;
use App\Modules\ModuleRuntimeEligibility;
use Throwable;

final class ModuleApiPolicy
{
    public function __construct(
        private readonly ModuleRuntimeEligibility $eligibility,
        private readonly UserModuleMembershipService $memberships
    ) {}

    public function assertAvailable(string $module): SystemModule
    {
        try {
            return $this->eligibility->assertExecutable($module);
        } catch (Throwable) {
            throw new UserApiException('模块当前未启用。', 403, 'module_unavailable');
        }
    }

    public function assertUserAccess(string $module, UserAccount $user): SystemModule
    {
        $record = $this->assertAvailable($module);
        $this->memberships->assertActive((int) $user->id, $module);

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
