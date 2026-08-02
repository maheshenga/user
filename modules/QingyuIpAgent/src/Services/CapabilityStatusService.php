<?php

namespace Modules\QingyuIpAgent\Services;

use App\Contracts\Modules\VipGateway;
use App\Models\UserAccount;
use App\User\UserModuleMembershipService;
use Modules\QingyuIpAgent\Exceptions\ContentCapabilityException;

final class CapabilityStatusService
{
    private const MODULE = 'qingyu_ip_agent';

    public function __construct(
        private readonly UserModuleMembershipService $memberships,
        private readonly VipGateway $vip,
        private readonly RewriteService $rewrite
    ) {}

    public function forUser(UserAccount $user): array
    {
        $this->memberships->assertActive((int) $user->id, self::MODULE);
        $vip = $this->vip->summary((int) $user->id);
        if (! ($vip['active'] ?? false)) {
            throw new ContentCapabilityException('需要有效的 VIP 会员权限。', 403, 'vip_required');
        }

        $rewrite = $this->rewrite->publicStatus();

        return [
            'provider' => 'platform-managed',
            'parse_available' => true,
            'rewrite_available' => $rewrite['available'],
            'model' => $rewrite['model'],
        ];
    }
}
