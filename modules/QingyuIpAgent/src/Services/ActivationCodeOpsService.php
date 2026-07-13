<?php

namespace Modules\QingyuIpAgent\Services;

use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\User\ActivationCodeService;

class ActivationCodeOpsService
{
    public function __construct(
        private readonly ActivationCodeService $codes,
        private readonly AuditLogService $audit
    ) {}

    public function batches(array $filters, int $page, int $limit): array
    {
        $query = ActivationCodeBatch::query();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')->forPage(max(1, $page), max(1, $limit))->get()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public function createBatch(array $payload, int $adminId): array
    {
        try {
            $batch = $this->codes->createBatch($payload, $adminId);
            $this->audit->record('activation_code.create_batch', 'activation_code_batch', (int) $batch['id'], $payload, 'success');

            return $batch;
        } catch (\Throwable $exception) {
            $this->audit->record('activation_code.create_batch', 'activation_code_batch', null, $payload, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    public function generateCodes(int $batchId, int $count, int $adminId): array
    {
        try {
            $result = $this->codes->generateCodes($batchId, $count, $adminId);
            $this->audit->record('activation_code.generate', 'activation_code_batch', $batchId, [
                'batch_id' => $batchId,
                'count' => $count,
            ], 'success');

            return $result;
        } catch (\Throwable $exception) {
            $this->audit->record('activation_code.generate', 'activation_code_batch', $batchId, [
                'batch_id' => $batchId,
                'count' => $count,
            ], 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    public function codes(array $filters, int $page, int $limit): array
    {
        $query = ActivationCode::query();
        if (! empty($filters['batch_id'])) {
            $query->where('batch_id', (int) $filters['batch_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')->forPage(max(1, $page), max(1, $limit))->get()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public function redemptions(array $filters, int $page, int $limit): array
    {
        $query = ActivationCodeRedemption::query();
        if (! empty($filters['result'])) {
            $query->where('result', $filters['result']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        $total = (clone $query)->count();
        $list = $query->orderByDesc('id')->forPage(max(1, $page), max(1, $limit))->get()->toArray();

        return ['total' => $total, 'list' => $list];
    }
}
