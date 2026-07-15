<?php

namespace App\Modules;

final class ModuleCapabilityPolicy
{
    public function __construct(private readonly ModuleExecutionContext $context) {}

    public function authorize(string $capability, ?string $ownedModule = null): ModuleIdentity
    {
        $identity = $this->context->requireCurrent();
        if (! $identity->can($capability)) {
            throw new ModuleApiException(
                "模块 [{$identity->name}] 未声明能力 [{$capability}]。",
                403,
                'module_capability_denied'
            );
        }

        if (
            ! $identity->isHost()
            && $ownedModule !== null
            && trim($ownedModule) !== ''
            && $ownedModule !== $identity->name
        ) {
            throw new ModuleApiException('模块不能访问其他模块的数据。', 403, 'module_ownership_denied');
        }

        return $identity;
    }
}
