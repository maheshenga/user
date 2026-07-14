<?php

namespace App\User;

use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;

final class UserOpsDashboardService
{
    public function metrics(): array
    {
        $todayStart = now()->startOfDay()->timestamp;
        $tomorrowStart = now()->copy()->addDay()->startOfDay()->timestamp;

        $todayCommission = AffiliateCommission::query()
            ->where('create_time', '>=', $todayStart)
            ->where('create_time', '<', $tomorrowStart)
            ->sum('amount');

        return [
            'total_users' => UserAccount::query()->count(),
            'today_registrations' => UserAccount::query()
                ->where('create_time', '>=', $todayStart)
                ->where('create_time', '<', $tomorrowStart)
                ->count(),
            'active_vip_users' => UserAccount::query()
                ->where('vip_level', '>', 0)
                ->where(function ($query): void {
                    $query->whereNull('vip_expires_at')->orWhere('vip_expires_at', '>', now());
                })
                ->count(),
            'pending_withdrawals' => UserWithdrawalRequest::query()
                ->where('status', 'pending')
                ->count(),
            'pending_payouts' => UserWithdrawalRequest::query()
                ->whereIn('status', ['approved', 'payout_failed'])
                ->count(),
            'pending_notifications' => UserNotificationOutbox::query()
                ->where('status', 'pending')
                ->count(),
            'retryable_notifications' => UserNotificationOutbox::query()
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->count(),
            'risk_events' => UserRiskEvent::query()->count(),
            'today_commission_amount' => $this->money($todayCommission),
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
