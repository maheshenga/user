<?php

namespace App\User;

use App\Models\ActivationCode;
use App\Models\ActivationCodeBatch;
use App\Models\ActivationCodeRedemption;
use App\Models\VipPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ActivationCodeService
{
    public function __construct(
        private readonly VipService $vip,
        private readonly AffiliateService $affiliate,
        private readonly RiskService $risk
    ) {
    }

    public function createBatch(array $payload, ?int $adminId): array
    {
        $plan = VipPlan::query()->find((int) ($payload['vip_plan_id'] ?? 0));
        if ($plan === null || $plan->status !== 'active') {
            throw new InvalidArgumentException('VIP 套餐未启用。');
        }

        $name = $this->normalizeNullableString($payload['name'] ?? null);
        if ($name === null) {
            throw new InvalidArgumentException('批次名称不能为空。');
        }

        $totalCount = max(0, (int) ($payload['total_count'] ?? 0));
        if ($totalCount <= 0) {
            throw new InvalidArgumentException('生成总数必须大于 0。');
        }

        $now = time();
        $batch = ActivationCodeBatch::query()->create([
            'name' => $name,
            'vip_plan_id' => $plan->id,
            'duration_days' => (int) ($payload['duration_days'] ?? $plan->duration_days),
            'total_count' => $totalCount,
            'generated_count' => 0,
            'status' => (string) ($payload['status'] ?? 'draft'),
            'is_commissionable' => (bool) ($payload['is_commissionable'] ?? false),
            'first_level_reward' => (float) ($payload['first_level_reward'] ?? 0),
            'second_level_reward' => (float) ($payload['second_level_reward'] ?? 0),
            'expires_at' => $payload['expires_at'] ?? null,
            'create_admin_id' => $adminId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->publicBatch($batch);
    }

    public function generateCodes(int $batchId, int $count, ?int $adminId): array
    {
        unset($adminId);

        return DB::transaction(function () use ($batchId, $count): array {
            $batch = ActivationCodeBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null || $batch->status !== 'active') {
                throw new InvalidArgumentException('激活码批次未启用。');
            }

            $count = max(0, $count);
            if ($count <= 0) {
                throw new InvalidArgumentException('生成数量必须大于 0。');
            }

            $remaining = (int) $batch->total_count - (int) $batch->generated_count;
            if ($count > $remaining) {
                throw new InvalidArgumentException('生成数量超过批次剩余容量。');
            }

            $codes = [];
            $now = time();
            for ($i = 0; $i < $count; $i++) {
                $plainCode = $this->uniquePlainCode();
                $normalized = $this->normalizeCode($plainCode);

                ActivationCode::query()->create([
                    'batch_id' => $batch->id,
                    'code_hash' => $this->hashCode($normalized),
                    'display_code_tail' => substr($normalized, -6),
                    'status' => 'unused',
                    'max_uses' => 1,
                    'used_count' => 0,
                    'expires_at' => $batch->expires_at,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $codes[] = $plainCode;
            }

            $batch->forceFill([
                'generated_count' => ((int) $batch->generated_count) + $count,
                'update_time' => $now,
            ])->save();

            return [
                'batch' => $this->publicBatch($batch->refresh()),
                'codes' => $codes,
            ];
        });
    }

    public function redeem(array $payload, int $userId, string $ip): array
    {
        $plainCode = $this->normalizeNullableString($payload['code'] ?? null);
        if ($plainCode === null) {
            throw new InvalidArgumentException('激活码不能为空。');
        }

        $normalized = $this->normalizeCode($plainCode);
        $result = DB::transaction(function () use ($normalized, $userId, $ip): array {
            $code = ActivationCode::query()
                ->where('code_hash', $this->hashCode($normalized))
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                $this->writeRedemption(null, null, $userId, null, null, $ip, 'failed', '激活码无效。');

                return ['error' => '激活码无效。'];
            }

            $batch = ActivationCodeBatch::query()->lockForUpdate()->find($code->batch_id);
            $error = $this->redemptionError($code, $batch, $userId);
            if ($error !== null) {
                $this->writeRedemption($code, $batch, $userId, null, null, $ip, 'failed', $error);

                return ['error' => $error];
            }

            $now = time();
            $code->forceFill([
                'used_count' => ((int) $code->used_count) + 1,
                'status' => ((int) $code->used_count + 1) >= (int) $code->max_uses ? 'used' : $code->status,
                'update_time' => $now,
            ])->save();

            $vip = $this->vip->grant($userId, (int) $batch->vip_plan_id, 'activation_code', (int) $code->id);
            $commissions = $this->affiliate->createForActivationCode(
                buyerUserId: $userId,
                activationCodeId: (int) $code->id,
                firstLevelReward: $batch->first_level_reward,
                secondLevelReward: $batch->second_level_reward,
                isCommissionable: (bool) $batch->is_commissionable
            );
            $commissionSourceId = $commissions[0]['id'] ?? null;
            $this->writeRedemption($code, $batch, $userId, (int) $vip['vip_record_id'], $commissionSourceId, $ip, 'success', '');

            return [
                'redeemed' => true,
                'activation_code_id' => (int) $code->id,
                'batch_id' => (int) $batch->id,
                'vip' => $vip,
            ];
        });

        if (isset($result['error'])) {
            $this->risk->recordActivationFailure($userId, $ip, $result['error']);

            throw new InvalidArgumentException($result['error']);
        }

        return $result;
    }

    private function redemptionError(ActivationCode $code, ?ActivationCodeBatch $batch, int $userId): ?string
    {
        if ($batch === null || $batch->status !== 'active') {
            return '激活码批次未启用。';
        }

        if ($code->status !== 'unused') {
            return '激活码当前不可用。';
        }

        if ($code->expires_at !== null && Carbon::parse($code->expires_at)->isPast()) {
            return '激活码已过期。';
        }

        if ($batch->expires_at !== null && Carbon::parse($batch->expires_at)->isPast()) {
            return '激活码已过期。';
        }

        if ((int) $code->max_uses > 0 && (int) $code->used_count >= (int) $code->max_uses) {
            return '激活码使用次数已达上限。';
        }

        if ($code->bound_user_id !== null && (int) $code->bound_user_id !== $userId) {
            return '激活码不属于当前用户。';
        }

        return null;
    }

    private function writeRedemption(
        ?ActivationCode $code,
        ?ActivationCodeBatch $batch,
        int $userId,
        ?int $vipRecordId,
        ?int $commissionSourceId,
        string $ip,
        string $result,
        string $errorMessage
    ): void {
        ActivationCodeRedemption::query()->create([
            'activation_code_id' => $code?->id,
            'batch_id' => $batch?->id,
            'user_id' => $userId,
            'vip_record_id' => $vipRecordId,
            'commission_source_id' => $commissionSourceId,
            'redeem_ip' => $ip,
            'result' => $result,
            'error_message' => $errorMessage,
            'create_time' => time(),
        ]);
    }

    private function uniquePlainCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->formatCode($this->randomAlphaNumeric(24));

            if (! ActivationCode::query()->where('code_hash', $this->hashCode($this->normalizeCode($code)))->exists()) {
                return $code;
            }
        }

        throw new InvalidArgumentException('无法生成激活码。');
    }

    private function randomAlphaNumeric(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $result = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    private function formatCode(string $body): string
    {
        return 'EA8-'.implode('-', str_split($body, 4));
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    private function hashCode(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function publicBatch(ActivationCodeBatch $batch): array
    {
        return [
            'id' => (int) $batch->id,
            'name' => $batch->name,
            'vip_plan_id' => (int) $batch->vip_plan_id,
            'duration_days' => (int) $batch->duration_days,
            'total_count' => (int) $batch->total_count,
            'generated_count' => (int) $batch->generated_count,
            'status' => $batch->status,
            'is_commissionable' => (bool) $batch->is_commissionable,
            'first_level_reward' => (string) $batch->first_level_reward,
            'second_level_reward' => (string) $batch->second_level_reward,
            'expires_at' => $batch->expires_at,
            'create_admin_id' => $batch->create_admin_id === null ? null : (int) $batch->create_admin_id,
        ];
    }
}
