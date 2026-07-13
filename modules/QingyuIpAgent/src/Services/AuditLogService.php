<?php

namespace Modules\QingyuIpAgent\Services;

use Modules\QingyuIpAgent\Models\QingyuIpAgentOperationLog;

class AuditLogService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_again',
        'currentpassword',
        'newpassword',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'code',
        'activation_code',
    ];

    public function record(
        string $action,
        ?string $targetType,
        ?int $targetId,
        array $payload,
        string $result,
        ?string $errorMessage = null
    ): void {
        QingyuIpAgentOperationLog::query()->create([
            'admin_id' => $this->adminId(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'masked_payload_json' => json_encode($this->maskPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result' => $result,
            'error_message' => $this->truncateErrorMessage($errorMessage),
            'ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'create_time' => time(),
        ]);
    }

    public function paginate(array $filters, int $page, int $limit): array
    {
        $query = QingyuIpAgentOperationLog::query();
        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.$filters['action'].'%');
        }
        if (! empty($filters['result'])) {
            $query->where('result', $filters['result']);
        }

        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage(max(1, $page), max(1, $limit))
            ->get()
            ->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public function maskPayload(array $payload): array
    {
        $masked = [];
        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (is_array($value)) {
                $masked[$key] = $this->maskPayload($value);
                continue;
            }

            $stringValue = is_scalar($value) || $value === null ? (string) $value : '[object]';
            if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
                $masked[$key] = $this->maskSecret($stringValue, $lowerKey);
            } elseif ($lowerKey === 'mobile') {
                $masked[$key] = $this->maskMobile($stringValue);
            } elseif ($lowerKey === 'email') {
                $masked[$key] = $this->maskEmail($stringValue);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function maskSecret(string $value, string $key): string
    {
        if (str_contains($key, 'code') && str_starts_with(strtoupper($value), 'EA8-')) {
            $tail = substr($value, -4);

            return 'EA8-****-'.$tail;
        }

        return '******';
    }

    private function truncateErrorMessage(?string $errorMessage): ?string
    {
        return $errorMessage === null ? null : mb_substr($errorMessage, 0, 500, 'UTF-8');
    }

    private function maskMobile(string $value): string
    {
        if (strlen($value) < 7) {
            return '***';
        }

        return substr($value, 0, 3).'****'.substr($value, -4);
    }

    private function maskEmail(string $value): string
    {
        [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        $prefix = strlen($name) <= 2
            ? substr($name, 0, 1)
            : substr($name, 0, 1).'***'.substr($name, -1);

        return $prefix.'@'.$domain;
    }

    private function adminId(): ?int
    {
        $adminId = session('admin.id');

        return $adminId === null ? null : (int) $adminId;
    }
}
