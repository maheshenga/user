<?php

namespace App\Modules;

use App\Models\ModuleApiRequest;
use App\Models\SystemModule;
use App\Models\UserAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ModuleApiRequestService
{
    public function requestId(mixed $candidate): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            return (string) Str::uuid();
        }
        if (strlen($candidate) > 80 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/', $candidate) !== 1) {
            throw new ModuleApiException('请求 ID 格式无效。', 422, 'request_id_invalid');
        }

        return $candidate;
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{request_id:string,data:array<string,mixed>,replayed:bool}
     */
    public function execute(
        string $module,
        int $userId,
        string $operation,
        string $requestId,
        array $payload,
        callable $callback,
    ): array {
        $requestHash = hash('sha256', json_encode($this->canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $leaseToken = (string) Str::uuid();
        $claim = DB::transaction(function () use ($module, $userId, $operation, $requestId, $requestHash, $leaseToken): array {
            UserAccount::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = ModuleApiRequest::query()
                ->where('module', $module)
                ->where('user_id', $userId)
                ->where('operation', $operation)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (
                    hash_equals((string) $existing->request_hash, $requestHash)
                    && $existing->status === 'processing'
                    && ($existing->lease_expires_at === null || ! $existing->lease_expires_at->isFuture())
                ) {
                    $existing->forceFill([
                        'lease_token' => $leaseToken,
                        'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                        'attempt_count' => max(1, (int) $existing->attempt_count) + 1,
                        'response_json' => null,
                        'http_status' => null,
                        'error_code' => null,
                        'finished_at' => null,
                    ])->save();

                    return ['request' => $existing->refresh()];
                }

                return ['existing' => $existing];
            }

            $quota = $this->dailyQuota($module, $operation);
            $used = ModuleApiRequest::query()
                ->where('module', $module)
                ->where('user_id', $userId)
                ->where('operation', $operation)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
            if ($used >= $quota) {
                throw new ModuleApiException('今日调用次数已达上限。', 429, 'quota_exceeded');
            }

            return ['request' => ModuleApiRequest::query()->create([
                'module' => $module,
                'user_id' => $userId,
                'operation' => $operation,
                'request_id' => $requestId,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'lease_token' => $leaseToken,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'attempt_count' => 1,
            ])];
        });

        if (isset($claim['existing'])) {
            return $this->replay($claim['existing'], $requestHash);
        }

        /** @var ModuleApiRequest $record */
        $record = $claim['request'];
        request()->attributes->set('module_request_id', $requestId);
        request()->attributes->set('module_request_started_at', microtime(true));
        request()->attributes->set('module_operation', $operation);

        try {
            $data = $callback();
            $this->complete($record, $leaseToken, $data);

            return ['request_id' => $requestId, 'data' => $data, 'replayed' => false];
        } catch (ModuleApiException $exception) {
            if (! $this->recordFailure($record, $leaseToken, $exception)) {
                throw $this->leaseLost($exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            $typed = new ModuleApiException('模块服务暂时不可用，请稍后重试。', 500, 'module_unavailable', $exception);
            if (! $this->recordFailure($record, $leaseToken, $typed)) {
                throw $this->leaseLost($exception);
            }
            throw $typed;
        }
    }

    private function replay(ModuleApiRequest $record, string $requestHash): array
    {
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw new ModuleApiException('相同请求 ID 不能用于不同载荷。', 409, 'idempotency_conflict');
        }
        if ($record->status === 'processing') {
            throw new ModuleApiException('相同请求正在处理中。', 409, 'request_in_progress');
        }
        if ($record->status === 'failed') {
            $response = is_array($record->response_json) ? $record->response_json : [];
            throw new ModuleApiException(
                (string) ($response['message'] ?? '模块请求失败。'),
                (int) ($record->http_status ?: 422),
                (string) ($record->error_code ?: 'module_request_failed')
            );
        }

        return [
            'request_id' => (string) $record->request_id,
            'data' => is_array($record->response_json) ? $record->response_json : [],
            'replayed' => true,
        ];
    }

    private function complete(ModuleApiRequest $record, string $leaseToken, array $data): void
    {
        $updated = ModuleApiRequest::query()
            ->whereKey($record->id)
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'completed',
                'response_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'http_status' => 200,
                'error_code' => null,
                'finished_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new ModuleApiException('模块请求执行租约已失效。', 409, 'request_lease_lost');
        }
    }

    private function recordFailure(ModuleApiRequest $record, string $leaseToken, ModuleApiException $exception): bool
    {
        request()->attributes->set('module_error_code', $exception->errorCode());
        $updated = ModuleApiRequest::query()
            ->whereKey($record->id)
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'failed',
                'response_json' => json_encode(
                    ['message' => $exception->getMessage()],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'http_status' => $exception->httpStatus(),
                'error_code' => $exception->errorCode(),
                'finished_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $updated === 1;
    }

    private function leaseLost(?Throwable $previous = null): ModuleApiException
    {
        return new ModuleApiException('模块请求执行租约已失效。', 409, 'request_lease_lost', $previous);
    }

    private function leaseSeconds(): int
    {
        return max(30, min(3600, (int) config('modules.api_request_lease_seconds', 180)));
    }

    private function dailyQuota(string $module, string $operation): int
    {
        $quotas = (array) config('modules.api_daily_quotas', []);
        $configured = isset($quotas[$module][$operation]) ? (int) $quotas[$module][$operation] : null;
        $manifest = SystemModule::query()->where('name', $module)->value('config_json');
        if (is_string($manifest)) {
            $manifest = json_decode($manifest, true);
        }
        $declared = is_array($manifest)
            && is_array($manifest['api'] ?? null)
            && is_array($manifest['api']['quotas'] ?? null)
            && isset($manifest['api']['quotas'][$operation])
                ? (int) $manifest['api']['quotas'][$operation]
                : null;
        $fallback = max(1, (int) config('modules.api_default_daily_quota', 500));
        $limits = array_values(array_filter([$configured, $declared], static fn (?int $value): bool => $value !== null && $value > 0));

        return $limits === [] ? $fallback : min($limits);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
