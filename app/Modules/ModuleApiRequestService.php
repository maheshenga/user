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
        $claim = DB::transaction(function () use ($module, $userId, $operation, $requestId, $requestHash): array {
            UserAccount::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = ModuleApiRequest::query()
                ->where('module', $module)
                ->where('user_id', $userId)
                ->where('operation', $operation)
                ->where('request_id', $requestId)
                ->first();
            if ($existing !== null) {
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
            $record->forceFill([
                'status' => 'completed',
                'response_json' => $data,
                'http_status' => 200,
                'finished_at' => now(),
            ])->save();

            return ['request_id' => $requestId, 'data' => $data, 'replayed' => false];
        } catch (ModuleApiException $exception) {
            $this->recordFailure($record, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $typed = new ModuleApiException('模块服务暂时不可用，请稍后重试。', 500, 'module_unavailable', $exception);
            $this->recordFailure($record, $typed);
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

    private function recordFailure(ModuleApiRequest $record, ModuleApiException $exception): void
    {
        request()->attributes->set('module_error_code', $exception->errorCode());
        $record->forceFill([
            'status' => 'failed',
            'response_json' => ['message' => $exception->getMessage()],
            'http_status' => $exception->httpStatus(),
            'error_code' => $exception->errorCode(),
            'finished_at' => now(),
        ])->save();
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
