<?php

namespace Modules\QingyuIpAgent\Services;

use Modules\QingyuIpAgent\Models\QingyuIpAgentOperationLog;

class AuditLogService
{
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'credential',
    ];

    private const SENSITIVE_EXACT_KEYS = [
        'authorization',
        'cookie',
        'session',
        'apikey',
        'privatekey',
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
            'request_id' => request()->attributes->get('module_request_id'),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'masked_payload_json' => json_encode($this->maskPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result' => $result,
            'error_code' => $this->errorCode($result),
            'duration_ms' => $this->durationMs(),
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
            $canonicalKey = $this->canonicalKey($key);
            if ($this->isSensitiveKey($canonicalKey)) {
                $masked[$key] = is_array($value) || is_object($value)
                    ? '******'
                    : $this->maskSecret((string) $value, $canonicalKey);

                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->maskPayload($value);

                continue;
            }

            if (is_object($value)) {
                $masked[$key] = $this->maskPayload((array) $value);

                continue;
            }

            $stringValue = is_scalar($value) || $value === null ? (string) $value : '[object]';
            if ($canonicalKey === 'mobile') {
                $masked[$key] = $this->maskMobile($stringValue);
            } elseif ($canonicalKey === 'email') {
                $masked[$key] = $this->maskEmail($stringValue);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function canonicalKey(string|int $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $key)) ?? '';
    }

    private function isSensitiveKey(string $key): bool
    {
        if ($key === 'code' || str_ends_with($key, 'code')) {
            return true;
        }

        if (in_array($key, self::SENSITIVE_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
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

    private function durationMs(): ?int
    {
        $startedAt = request()->attributes->get('module_request_started_at');

        return is_numeric($startedAt) ? max(0, (int) round((microtime(true) - (float) $startedAt) * 1000)) : null;
    }

    private function errorCode(string $result): ?string
    {
        $explicit = request()->attributes->get('module_error_code');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        if ($result !== 'failed') {
            return null;
        }

        return match ((string) request()->attributes->get('module_operation')) {
            'activation.redeem' => 'activation_invalid',
            'content.parse' => 'content_parse_failed',
            'content.rewrite' => 'content_rewrite_failed',
            default => 'module_request_failed',
        };
    }
}
