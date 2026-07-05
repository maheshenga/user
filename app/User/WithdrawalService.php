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
            $withdrawal = $this->lockedWithdrawal($withdrawalId);
            if ($withdrawal->status !== 'pending') {
                throw new InvalidArgumentException('Only pending withdrawal can be approved.');
            }

            $withdrawal->forceFill([
                'status' => 'approved',
                'audit_admin_id' => $adminId,
                'audited_at' => now(),
                'approved_admin_id' => $adminId,
                'approved_at' => now(),
                'update_time' => time(),
            ])->save();

            return $this->publicWithdrawal($withdrawal->refresh());
        });
    }

    public function markPaid(int $withdrawalId, array $payload, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        $method = trim((string) ($payload['method'] ?? ''));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        $proof = $payload['proof'] ?? [];
        if (! is_array($proof)) {
            $proof = [];
        }

        if ($method === '') {
            throw new InvalidArgumentException('Payout method is required.');
        }

        if ($transactionId === '') {
            throw new InvalidArgumentException('Payout transaction id is required.');
        }

        return DB::transaction(function () use ($withdrawalId, $method, $transactionId, $proof, $adminId): array {
            $withdrawal = $this->lockedWithdrawal($withdrawalId);
            if (! in_array($withdrawal->status, ['approved', 'payout_failed'], true)) {
                throw new InvalidArgumentException('Only approved or failed payout withdrawal can be marked paid.');
            }

            if ($withdrawal->ledger_success_id !== null) {
                throw new InvalidArgumentException('Withdrawal payout has already been settled.');
            }

            $ledger = $this->balanceLedger->settleFrozen(
                (int) $withdrawal->user_id,
                $withdrawal->amount,
                'withdraw_success',
                'user_withdrawal_request',
                (int) $withdrawal->id,
                'Withdrawal payout paid',
                $adminId
            );

            $withdrawal->forceFill([
                'status' => 'paid',
                'ledger_success_id' => $ledger['id'],
                'payout_admin_id' => $adminId,
                'payout_method' => $method,
                'payout_transaction_id' => $transactionId,
                'payout_proof_json' => $proof,
                'payout_error' => '',
                'payout_attempt_count' => ((int) $withdrawal->payout_attempt_count) + 1,
                'payout_last_attempt_at' => now(),
                'paid_at' => now(),
                'update_time' => time(),
            ])->save();

            return $this->publicWithdrawal($withdrawal->refresh());
        });
    }

    public function markPayoutFailed(int $withdrawalId, string $error, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Admin id is required.');
        }

        $error = trim($error);
        if ($error === '') {
            throw new InvalidArgumentException('Payout error is required.');
        }

        return DB::transaction(function () use ($withdrawalId, $error, $adminId): array {
            $withdrawal = $this->lockedWithdrawal($withdrawalId);
            if (! in_array($withdrawal->status, ['approved', 'payout_failed'], true)) {
                throw new InvalidArgumentException('Only approved or failed payout withdrawal can be marked failed.');
            }

            if ($withdrawal->ledger_success_id !== null) {
                throw new InvalidArgumentException('Paid withdrawal cannot be marked failed.');
            }

            $withdrawal->forceFill([
                'status' => 'payout_failed',
                'payout_admin_id' => $adminId,
                'payout_error' => substr($error, 0, 1000),
                'payout_attempt_count' => ((int) $withdrawal->payout_attempt_count) + 1,
                'payout_last_attempt_at' => now(),
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
            $withdrawal = $this->lockedWithdrawal($withdrawalId);
            if (! in_array($withdrawal->status, ['pending', 'approved', 'payout_failed'], true)) {
                throw new InvalidArgumentException('Only pending, approved, or failed payout withdrawal can be rejected.');
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

    public function stats(): array
    {
        $rows = UserWithdrawalRequest::query()
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string) $row->status] = [
                'count' => (int) $row->total,
                'amount' => $this->money($row->amount ?? 0),
            ];
        }

        $pendingPayout = UserWithdrawalRequest::query()
            ->whereIn('status', ['approved', 'payout_failed'])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->first();

        return [
            'by_status' => $byStatus,
            'pending_payout_count' => (int) ($pendingPayout->total ?? 0),
            'pending_payout_amount' => $this->money($pendingPayout->amount ?? 0),
        ];
    }

    private function lockedWithdrawal(int $withdrawalId): UserWithdrawalRequest
    {
        $withdrawal = UserWithdrawalRequest::query()->lockForUpdate()->find($withdrawalId);
        if ($withdrawal === null) {
            throw new InvalidArgumentException('Withdrawal request not found.');
        }

        return $withdrawal;
    }

    private function positiveMoney(string|float $amount): string
    {
        $money = $this->money($amount);
        if ((float) $money <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $money;
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
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
            'amount' => $this->money($withdrawal->amount),
            'status' => $withdrawal->status,
            'request_ip' => $withdrawal->request_ip,
            'account_snapshot_json' => $withdrawal->account_snapshot_json,
            'ledger_freeze_id' => $withdrawal->ledger_freeze_id === null ? null : (int) $withdrawal->ledger_freeze_id,
            'ledger_success_id' => $withdrawal->ledger_success_id === null ? null : (int) $withdrawal->ledger_success_id,
            'reason' => $withdrawal->reason,
            'audit_admin_id' => $withdrawal->audit_admin_id === null ? null : (int) $withdrawal->audit_admin_id,
            'audited_at' => $withdrawal->audited_at,
            'approved_admin_id' => $withdrawal->approved_admin_id === null ? null : (int) $withdrawal->approved_admin_id,
            'approved_at' => $withdrawal->approved_at,
            'payout_admin_id' => $withdrawal->payout_admin_id === null ? null : (int) $withdrawal->payout_admin_id,
            'payout_method' => $withdrawal->payout_method,
            'payout_transaction_id' => $withdrawal->payout_transaction_id,
            'payout_proof_json' => $withdrawal->payout_proof_json ?: [],
            'payout_error' => $withdrawal->payout_error,
            'payout_attempt_count' => (int) $withdrawal->payout_attempt_count,
            'payout_last_attempt_at' => $withdrawal->payout_last_attempt_at,
            'paid_at' => $withdrawal->paid_at,
        ];
    }
}
