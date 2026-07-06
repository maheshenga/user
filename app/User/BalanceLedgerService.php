<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserBalanceLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BalanceLedgerService
{
    public function credit(
        int $userId,
        string|float $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId = null
    ): array {
        return $this->mutate($userId, $amount, 'in', $type, $sourceType, $sourceId, $remark, $adminId);
    }

    public function debit(
        int $userId,
        string|float $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId = null
    ): array {
        return $this->mutate($userId, $amount, 'out', $type, $sourceType, $sourceId, $remark, $adminId);
    }

    public function freeze(
        int $userId,
        string|float $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId = null
    ): array {
        return $this->mutate($userId, $amount, 'freeze', $type, $sourceType, $sourceId, $remark, $adminId);
    }

    public function unfreeze(
        int $userId,
        string|float $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId = null
    ): array {
        return $this->mutate($userId, $amount, 'unfreeze', $type, $sourceType, $sourceId, $remark, $adminId);
    }

    public function settleFrozen(
        int $userId,
        string|float $amount,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId = null
    ): array {
        return $this->mutate($userId, $amount, 'settle_frozen', $type, $sourceType, $sourceId, $remark, $adminId);
    }

    public function adminAdjust(int $userId, string|float $amount, string $reason, int $adminId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('调整原因不能为空。');
        }

        if ($adminId <= 0) {
            throw new InvalidArgumentException('管理员 ID 不能为空。');
        }

        $signedAmount = $this->normalizeSignedAmount($amount);
        if ($signedAmount === '0.00') {
            throw new InvalidArgumentException('调整金额不能为 0。');
        }

        $ledger = str_starts_with($signedAmount, '-')
            ? $this->debit($userId, ltrim($signedAmount, '-'), 'admin_adjust', null, null, $reason, $adminId)
            : $this->credit($userId, $signedAmount, 'admin_adjust', null, null, $reason, $adminId);

        $ledger['signed_amount'] = $signedAmount;

        return $ledger;
    }

    public function summary(int $userId): array
    {
        $user = UserAccount::query()->find($userId);
        if ($user === null) {
            throw new InvalidArgumentException('用户账户不存在。');
        }

        return [
            'user_id' => (int) $user->id,
            'available_balance' => $this->money($user->available_balance ?? 0),
            'frozen_balance' => $this->money($user->frozen_balance ?? 0),
        ];
    }

    public function ledger(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return UserBalanceLedger::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UserBalanceLedger $ledger): array => $this->publicLedger($ledger))
            ->all();
    }

    private function mutate(
        int $userId,
        string|float $amount,
        string $direction,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        string $remark,
        ?int $adminId
    ): array {
        $amount = $this->normalizePositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $direction, $type, $sourceType, $sourceId, $remark, $adminId): array {
            $user = UserAccount::query()->lockForUpdate()->find($userId);
            if ($user === null) {
                throw new InvalidArgumentException('用户账户不存在。');
            }

            $balanceBefore = $this->money($user->available_balance ?? 0);
            $frozenBefore = $this->money($user->frozen_balance ?? 0);
            [$balanceAfter, $frozenAfter] = $this->nextSnapshots($direction, $balanceBefore, $frozenBefore, $amount);
            $now = time();

            $user->forceFill([
                'available_balance' => $balanceAfter,
                'frozen_balance' => $frozenAfter,
                'update_time' => $now,
            ])->save();

            $ledger = UserBalanceLedger::query()->create([
                'user_id' => $user->id,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'remark' => trim($remark),
                'admin_id' => $adminId,
                'create_time' => $now,
            ]);

            return $this->publicLedger($ledger);
        });
    }

    private function nextSnapshots(string $direction, string $balanceBefore, string $frozenBefore, string $amount): array
    {
        return match ($direction) {
            'in' => [$this->add($balanceBefore, $amount), $frozenBefore],
            'out' => [$this->subAvailable($balanceBefore, $amount), $frozenBefore],
            'freeze' => [$this->subAvailable($balanceBefore, $amount), $this->add($frozenBefore, $amount)],
            'unfreeze' => [$this->add($balanceBefore, $amount), $this->subFrozen($frozenBefore, $amount)],
            'settle_frozen' => [$balanceBefore, $this->subFrozen($frozenBefore, $amount)],
            default => throw new InvalidArgumentException('不支持的余额变动方向。'),
        };
    }

    private function subAvailable(string $balance, string $amount): string
    {
        if ($this->compare($balance, $amount) < 0) {
            throw new InvalidArgumentException('可用余额不足。');
        }

        return $this->sub($balance, $amount);
    }

    private function subFrozen(string $frozen, string $amount): string
    {
        if ($this->compare($frozen, $amount) < 0) {
            throw new InvalidArgumentException('冻结余额不足。');
        }

        return $this->sub($frozen, $amount);
    }

    private function normalizePositiveAmount(string|float $amount): string
    {
        $amount = $this->money($amount);
        if ($this->compare($amount, '0.00') <= 0) {
            throw new InvalidArgumentException('金额必须大于 0。');
        }

        return $amount;
    }

    private function normalizeSignedAmount(string|float $amount): string
    {
        return $this->money($amount);
    }

    private function add(string $left, string $right): string
    {
        return $this->money((float) $left + (float) $right);
    }

    private function sub(string $left, string $right): string
    {
        return $this->money((float) $left - (float) $right);
    }

    private function compare(string $left, string $right): int
    {
        return ((int) round(((float) $left) * 100)) <=> ((int) round(((float) $right) * 100));
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private function publicLedger(UserBalanceLedger $ledger): array
    {
        return [
            'id' => (int) $ledger->id,
            'user_id' => (int) $ledger->user_id,
            'direction' => $ledger->direction === 'settle_frozen' ? 'out' : $ledger->direction,
            'amount' => $this->money($ledger->amount),
            'balance_before' => $this->money($ledger->balance_before),
            'balance_after' => $this->money($ledger->balance_after),
            'frozen_before' => $this->money($ledger->frozen_before),
            'frozen_after' => $this->money($ledger->frozen_after),
            'type' => $ledger->type,
            'source_type' => $ledger->source_type,
            'source_id' => $ledger->source_id === null ? null : (int) $ledger->source_id,
            'remark' => $ledger->remark,
            'admin_id' => $ledger->admin_id === null ? null : (int) $ledger->admin_id,
            'create_time' => $ledger->create_time,
        ];
    }
}
