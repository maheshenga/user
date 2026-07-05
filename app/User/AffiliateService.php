<?php

namespace App\User;

use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserInviteRelation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AffiliateService
{
    public function __construct(private readonly BalanceLedgerService $balanceLedger)
    {
    }

    public function createForActivationCode(
        int $buyerUserId,
        int $activationCodeId,
        string|float $firstLevelReward,
        string|float $secondLevelReward,
        bool $isCommissionable
    ): array {
        return $this->createForSource(
            'activation_code',
            $activationCodeId,
            $buyerUserId,
            $this->money($firstLevelReward),
            $this->money($secondLevelReward),
            $isCommissionable
        );
    }

    public function createForVipOrder(
        int $buyerUserId,
        int $vipOrderId,
        string|float $amount,
        string|float $firstLevelRate,
        string|float $secondLevelRate,
        bool $isCommissionable
    ): array {
        $baseAmount = (float) $this->money($amount);

        return $this->createForSource(
            'vip_order',
            $vipOrderId,
            $buyerUserId,
            $this->money($baseAmount * (float) $firstLevelRate),
            $this->money($baseAmount * (float) $secondLevelRate),
            $isCommissionable
        );
    }

    public function approve(int $commissionId, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        return DB::transaction(function () use ($commissionId, $adminId): array {
            $commission = AffiliateCommission::query()->lockForUpdate()->find($commissionId);
            if ($commission === null) {
                throw new InvalidArgumentException('Commission not found.');
            }

            if ($commission->status !== 'pending') {
                throw new InvalidArgumentException('Only pending commission can be approved.');
            }

            $ledger = $this->balanceLedger->credit(
                (int) $commission->beneficiary_user_id,
                $commission->amount,
                'affiliate_commission',
                'affiliate_commission',
                (int) $commission->id,
                'Affiliate commission settlement',
                $adminId
            );

            $commission->forceFill([
                'status' => 'settled',
                'audit_admin_id' => $adminId,
                'audited_at' => Carbon::now(),
                'settled_ledger_id' => $ledger['id'],
                'update_time' => time(),
            ])->save();

            return $this->publicCommission($commission->refresh());
        });
    }

    public function reject(int $commissionId, string $reason, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reject reason is required.');
        }

        return DB::transaction(function () use ($commissionId, $reason, $adminId): array {
            $commission = AffiliateCommission::query()->lockForUpdate()->find($commissionId);
            if ($commission === null) {
                throw new InvalidArgumentException('Commission not found.');
            }

            if ($commission->status !== 'pending') {
                throw new InvalidArgumentException('Only pending commission can be rejected.');
            }

            $commission->forceFill([
                'status' => 'rejected',
                'reason' => $reason,
                'audit_admin_id' => $adminId,
                'audited_at' => Carbon::now(),
                'update_time' => time(),
            ])->save();

            return $this->publicCommission($commission->refresh());
        });
    }

    public function batchApprove(array $commissionIds, int $adminId): array
    {
        return $this->batchReview($commissionIds, fn (int $id): array => $this->approve($id, $adminId));
    }

    public function batchReject(array $commissionIds, string $reason, int $adminId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reject reason is required.');
        }

        return $this->batchReview($commissionIds, fn (int $id): array => $this->reject($id, $reason, $adminId));
    }

    public function stats(): array
    {
        $statuses = ['pending', 'settled', 'rejected', 'frozen', 'reversed'];
        $byStatus = array_fill_keys($statuses, ['count' => 0, 'amount' => '0.00']);

        $rows = AffiliateCommission::query()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            if (! array_key_exists($row->status, $byStatus)) {
                continue;
            }

            $byStatus[$row->status] = [
                'count' => (int) $row->count,
                'amount' => $this->money($row->amount),
            ];
        }

        $topBeneficiaries = AffiliateCommission::query()
            ->selectRaw('beneficiary_user_id, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->where('status', 'settled')
            ->groupBy('beneficiary_user_id')
            ->orderByRaw('COALESCE(SUM(amount), 0) DESC')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'beneficiary_user_id' => (int) $row->beneficiary_user_id,
                'count' => (int) $row->count,
                'amount' => $this->money($row->amount),
            ])
            ->all();

        return [
            'by_status' => $byStatus,
            'top_beneficiaries' => $topBeneficiaries,
        ];
    }

    public function reverse(int $commissionId, string $reason, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reverse reason is required.');
        }

        return DB::transaction(function () use ($commissionId, $reason, $adminId): array {
            $commission = AffiliateCommission::query()->lockForUpdate()->find($commissionId);
            if ($commission === null) {
                throw new InvalidArgumentException('Commission not found.');
            }

            if ($commission->status !== 'settled') {
                throw new InvalidArgumentException('Only settled commission can be reversed.');
            }

            $this->balanceLedger->debit(
                (int) $commission->beneficiary_user_id,
                $commission->amount,
                'reversal',
                'affiliate_commission',
                (int) $commission->id,
                $reason,
                $adminId
            );

            $commission->forceFill([
                'status' => 'reversed',
                'reason' => $reason,
                'audit_admin_id' => $adminId,
                'audited_at' => Carbon::now(),
                'update_time' => time(),
            ])->save();

            return $this->publicCommission($commission->refresh());
        });
    }

    private function createForSource(
        string $sourceType,
        int $sourceId,
        int $buyerUserId,
        string $firstLevelAmount,
        string $secondLevelAmount,
        bool $isCommissionable
    ): array {
        if (! $isCommissionable) {
            return [];
        }

        $relation = UserInviteRelation::query()
            ->where('user_id', $buyerUserId)
            ->where('status', 'active')
            ->first();

        if ($relation === null) {
            return [];
        }

        $levels = [
            1 => [(int) $relation->parent_user_id, $firstLevelAmount],
            2 => [$relation->grandparent_user_id === null ? 0 : (int) $relation->grandparent_user_id, $secondLevelAmount],
        ];

        return DB::transaction(function () use ($levels, $sourceType, $sourceId, $buyerUserId): array {
            $commissions = [];

            foreach ($levels as $level => [$beneficiaryUserId, $amount]) {
                if ($beneficiaryUserId <= 0 || $this->compare($amount, '0.00') <= 0) {
                    continue;
                }

                $beneficiary = UserAccount::query()->find($beneficiaryUserId);
                if ($beneficiary === null || $beneficiary->status !== 'active') {
                    continue;
                }

                $commission = AffiliateCommission::query()->firstOrCreate([
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'level' => $level,
                    'beneficiary_user_id' => $beneficiaryUserId,
                ], [
                    'buyer_user_id' => $buyerUserId,
                    'amount' => $amount,
                    'status' => 'pending',
                    'reason' => '',
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                $commissions[] = $this->publicCommission($commission);
            }

            return $commissions;
        });
    }

    private function batchReview(array $commissionIds, callable $review): array
    {
        $processed = [];
        $errors = [];

        foreach (array_values(array_unique(array_map('intval', $commissionIds))) as $id) {
            if ($id <= 0) {
                continue;
            }

            try {
                $processed[] = $review($id);
            } catch (InvalidArgumentException $exception) {
                $errors[] = [
                    'id' => $id,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
        ];
    }

    private function publicCommission(AffiliateCommission $commission): array
    {
        return [
            'id' => (int) $commission->id,
            'source_type' => $commission->source_type,
            'source_id' => (int) $commission->source_id,
            'buyer_user_id' => (int) $commission->buyer_user_id,
            'beneficiary_user_id' => (int) $commission->beneficiary_user_id,
            'level' => (int) $commission->level,
            'amount' => $this->money($commission->amount),
            'status' => $commission->status,
            'reason' => $commission->reason,
            'audit_admin_id' => $commission->audit_admin_id === null ? null : (int) $commission->audit_admin_id,
            'audited_at' => $commission->audited_at,
            'settled_ledger_id' => $commission->settled_ledger_id === null ? null : (int) $commission->settled_ledger_id,
            'reversed_commission_id' => $commission->reversed_commission_id === null ? null : (int) $commission->reversed_commission_id,
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private function compare(string $left, string $right): int
    {
        return ((int) round(((float) $left) * 100)) <=> ((int) round(((float) $right) * 100));
    }
}
