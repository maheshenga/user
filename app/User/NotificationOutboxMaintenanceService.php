<?php

namespace App\User;

use App\Models\UserNotificationOutbox;

final class NotificationOutboxMaintenanceService
{
    public function summary(): array
    {
        $now = now()->timestamp;
        $rows = UserNotificationOutbox::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byStatus = [];
        foreach ($rows as $status => $count) {
            $byStatus[(string) $status] = (int) $count;
        }

        $retryable = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now()->timestamp);
            })
            ->count();

        $delayed = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where('available_at', '>', $now)
            ->count();

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'retryable_pending' => (int) $retryable,
            'delayed_pending' => (int) $delayed,
        ];
    }

    public function purgeSentOlderThan(int $days, int $limit = 500): array
    {
        $days = max(1, $days);
        $limit = max(1, min(5000, $limit));
        $threshold = now()->subDays($days)->timestamp;

        $ids = UserNotificationOutbox::query()
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->where('create_time', '<', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return ['deleted' => 0, 'days' => $days, 'limit' => $limit];
        }

        $deleted = UserNotificationOutbox::query()->whereIn('id', $ids)->delete();

        return ['deleted' => (int) $deleted, 'days' => $days, 'limit' => $limit];
    }
}
