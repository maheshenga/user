<?php

namespace App\User;

use App\Models\UserWithdrawalRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WithdrawalService
{
    public function __construct(private readonly BalanceLedgerService $balanceLedger)
    {
    }

    public function request(int $userId, string|float $amount, array $accountSnapshot, string $ip): array
    {
        $amount = $this->positiveMoney($amount);
        if ($accountSnapshot === []) {
            throw new InvalidArgumentException('Withdrawal account snapshot is required.');
        }

        return DB::transaction(function () use ($userId, $amount, $accountSnapshot, $ip): array {
            $now = time();
            $withdrawal = UserWithdrawalRequest::query()->create([
                'withdrawal_no' => $this->withdrawalNo(),
                'user_id' => $userId,
                'amount' => $amount,
                'status' => 'pending',
                'request_ip' => $ip,
                'account_snapshot_json' => $accountSnapshot,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            $ledger = $this->balanceLedger->freeze(
                $userId,
                $amount,
                'withdraw_freeze',
                'user_withdrawal_request',
                (int) $withdrawal->id,
                'Withdrawal request freeze'
            );

            $withdrawal->forceFill([
                'ledger_freeze_id' => $ledger['id'],
                'update_time' => time(),
            ])->save();

            return $this->publicWithdrawal($withdrawal->refresh());
        });
    }

    public function approve(int $withdrawalId, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        return DB::transaction(function () use ($withdrawalId, $adminId): array {
            $withdrawal = UserWithdrawalRequest::query()->lockForUpdate()->find($withdrawalId);
            if ($withdrawal === null) {
                throw new InvalidArgumentException('Withdrawal request not found.');
            }

            if ($withdrawal->status !== 'pending') {
                throw new InvalidArgumentException('Only pending withdrawal can be approved.');
            }

            $ledger = $this->balanceLedger->settleFrozen(
                (int) $withdrawal->user_id,
                $withdrawal->amount,
                'withdraw_success',
                'user_withdrawal_request',
                (int) $withdrawal->id,
                'Withdrawal paid',
                $adminId
            );

            $withdrawal->forceFill([
                'status' => 'paid',
                'ledger_success_id' => $ledger['id'],
                'audit_admin_id' => $adminId,
                'audited_at' => now(),
                'update_time' => time(),
            ])->save();

            return $this->publicWithdrawal($withdrawal->refresh());
        });
    }

    public function reject(int $withdrawalId, string $reason, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Reject reason is required.');
        }

        return DB::transaction(function () use ($withdrawalId, $reason, $adminId): array {
            $withdrawal = UserWithdrawalRequest::query()->lockForUpdate()->find($withdrawalId);
            if ($withdrawal === null) {
                throw new InvalidArgumentException('Withdrawal request not found.');
            }

            if ($withdrawal->status !== 'pending') {
                throw new InvalidArgumentException('Only pending withdrawal can be rejected.');
            }

            $this->balanceLedger->unfreeze(
                (int) $withdrawal->user_id,
                $withdrawal->amount,
                'withdraw_reject',
                'user_withdrawal_request',
                (int) $withdrawal->id,
                $reason,
                $adminId
            );

            $withdrawal->forceFill([
                'status' => 'rejected',
                'reason' => $reason,
                'audit_admin_id' => $adminId,
                'audited_at' => now(),
                'update_time' => time(),
            ])->save();

            return $this->publicWithdrawal($withdrawal->refresh());
        });
    }

    public function listForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return UserWithdrawalRequest::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UserWithdrawalRequest $withdrawal): array => $this->publicWithdrawal($withdrawal))
            ->all();
    }

    private function positiveMoney(string|float $amount): string
    {
        $money = number_format(round((float) $amount, 2), 2, '.', '');
        if ((float) $money <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $money;
    }

    private function withdrawalNo(): string
    {
        return 'WD'.date('YmdHis').random_int(1000, 9999);
    }

    private function publicWithdrawal(UserWithdrawalRequest $withdrawal): array
    {
        return [
            'id' => (int) $withdrawal->id,
            'withdrawal_no' => $withdrawal->withdrawal_no,
            'user_id' => (int) $withdrawal->user_id,
            'amount' => number_format((float) $withdrawal->amount, 2, '.', ''),
            'status' => $withdrawal->status,
            'request_ip' => $withdrawal->request_ip,
            'account_snapshot_json' => $withdrawal->account_snapshot_json,
            'ledger_freeze_id' => $withdrawal->ledger_freeze_id === null ? null : (int) $withdrawal->ledger_freeze_id,
            'ledger_success_id' => $withdrawal->ledger_success_id === null ? null : (int) $withdrawal->ledger_success_id,
            'reason' => $withdrawal->reason,
            'audit_admin_id' => $withdrawal->audit_admin_id === null ? null : (int) $withdrawal->audit_admin_id,
            'audited_at' => $withdrawal->audited_at,
        ];
    }
}
