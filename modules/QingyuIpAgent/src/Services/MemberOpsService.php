<?php

namespace Modules\QingyuIpAgent\Services;

use App\Models\UserAccount;
use App\User\VipService;

class MemberOpsService
{
    public function __construct(
        private readonly VipService $vip,
        private readonly AuditLogService $audit
    ) {}

    public function paginate(array $filters, int $page, int $limit): array
    {
        $query = UserAccount::query();
        if (! empty($filters['keyword'])) {
            $keyword = (string) $filters['keyword'];
            $query->where(function ($query) use ($keyword): void {
                $query->where('mobile', 'like', '%'.$keyword.'%')
                    ->orWhere('email', 'like', '%'.$keyword.'%')
                    ->orWhere('nickname', 'like', '%'.$keyword.'%');
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage(max(1, $page), max(1, $limit))
            ->get()
            ->map(fn (UserAccount $user): array => $this->publicUser($user))
            ->all();

        return ['total' => $total, 'list' => $list];
    }

    public function detail(int $userId): array
    {
        $user = UserAccount::query()->findOrFail($userId);

        return $this->publicUser($user) + ['vip' => $this->vip->summary($userId)];
    }

    public function grantVip(int $userId, int $vipPlanId, int $adminId): array
    {
        try {
            $result = $this->vip->grant($userId, $vipPlanId, 'qingyu_ip_agent_admin_grant', $adminId);
            $this->audit->record('member.grant_vip', 'user_account', $userId, [
                'user_id' => $userId,
                'vip_plan_id' => $vipPlanId,
            ], 'success');

            return $result;
        } catch (\Throwable $exception) {
            $this->audit->record('member.grant_vip', 'user_account', $userId, [
                'user_id' => $userId,
                'vip_plan_id' => $vipPlanId,
            ], 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    private function publicUser(UserAccount $user): array
    {
        return [
            'id' => (int) $user->id,
            'mobile' => $this->maskMobile((string) $user->mobile),
            'email' => $this->maskEmail((string) $user->email),
            'nickname' => $user->nickname,
            'status' => $user->status,
            'source_module' => $user->source_module,
            'vip_level' => (int) $user->vip_level,
            'vip_expires_at' => $user->vip_expires_at?->toDateTimeString(),
            'create_time' => (string) $user->create_time,
        ];
    }

    private function maskMobile(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return strlen($value) < 7 ? '***' : substr($value, 0, 3).'****'.substr($value, -4);
    }

    private function maskEmail(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        return substr($name, 0, 1).'***'.(strlen($name) > 1 ? substr($name, -1) : '').'@'.$domain;
    }
}
