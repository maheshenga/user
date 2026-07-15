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

        $signedAmount = Money::from($amount);
        if ($signedAmount->isZero()) {
            throw new InvalidArgumentException('调整金额不能为 0。');
        }

        $ledger = $signedAmount->isNegative()
            ? $this->debit($userId, $signedAmount->absolute()->toString(), 'admin_adjust', null, null, $reason, $adminId)
            : $this->credit($userId, $signedAmount->toString(), 'admin_adjust', null, null, $reason, $adminId);

        $ledger['signed_amount'] = $signedAmount->toString();

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
            'available_balance' => Money::from($user->available_balance ?? 0)->toString(),
            'frozen_balance' => Money::from($user->frozen_balance ?? 0)->toString(),
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
        $amount = Money::from($amount);
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('金额必须大于 0。');
        }

        return DB::transaction(function () use ($userId, $amount, $direction, $type, $sourceType, $sourceId, $remark, $adminId): array {
            $user = UserAccount::query()->lockForUpdate()->find($userId);
            if ($user === null) {
                throw new InvalidArgumentException('用户账户不存在。');
            }

            $operationKey = BalanceOperationKey::make($userId, $direction, $type, $sourceType, $sourceId);
            if ($operationKey !== null) {
                $existing = UserBalanceLedger::query()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (Money::from($existing->amount)->compareTo($amount) !== 0) {
                        throw new InvalidArgumentException('余额操作幂等键冲突。');
                    }

                    return $this->publicLedger($existing);
                }
            }

            $balanceBefore = Money::from($user->available_balance ?? 0);
            $frozenBefore = Money::from($user->frozen_balance ?? 0);
            [$balanceAfter, $frozenAfter] = $this->nextSnapshots($direction, $balanceBefore, $frozenBefore, $amount);
            $now = time();

            $user->forceFill([
                'available_balance' => $balanceAfter->toString(),
                'frozen_balance' => $frozenAfter->toString(),
                'update_time' => $now,
            ])->save();

            $ledger = UserBalanceLedger::query()->create([
                'user_id' => $user->id,
                'direction' => $direction,
                'amount' => $amount->toString(),
                'balance_before' => $balanceBefore->toString(),
                'balance_after' => $balanceAfter->toString(),
                'frozen_before' => $frozenBefore->toString(),
                'frozen_after' => $frozenAfter->toString(),
                'type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'operation_key' => $operationKey,
                'remark' => trim($remark),
                'admin_id' => $adminId,
                'create_time' => $now,
            ]);

            return $this->publicLedger($ledger);
        });
    }

    private function nextSnapshots(string $direction, Money $balanceBefore, Money $frozenBefore, Money $amount): array
    {
        return match ($direction) {
            'in' => [$balanceBefore->add($amount), $frozenBefore],
            'out' => [$this->subAvailable($balanceBefore, $amount), $frozenBefore],
            'freeze' => [$this->subAvailable($balanceBefore, $amount), $frozenBefore->add($amount)],
            'unfreeze' => [$balanceBefore->add($amount), $this->subFrozen($frozenBefore, $amount)],
            'settle_frozen' => [$balanceBefore, $this->subFrozen($frozenBefore, $amount)],
            default => throw new InvalidArgumentException('不支持的余额变动方向。'),
        };
    }

    private function subAvailable(Money $balance, Money $amount): Money
    {
        if ($balance->compareTo($amount) < 0) {
            throw new InvalidArgumentException('可用余额不足。');
        }

        return $balance->subtract($amount);
    }

    private function subFrozen(Money $frozen, Money $amount): Money
    {
        if ($frozen->compareTo($amount) < 0) {
            throw new InvalidArgumentException('冻结余额不足。');
        }

        return $frozen->subtract($amount);
    }

    private function publicLedger(UserBalanceLedger $ledger): array
    {
        return [
            'id' => (int) $ledger->id,
            'user_id' => (int) $ledger->user_id,
            'direction' => $ledger->direction === 'settle_frozen' ? 'out' : $ledger->direction,
            'amount' => Money::from($ledger->amount)->toString(),
            'balance_before' => Money::from($ledger->balance_before)->toString(),
            'balance_after' => Money::from($ledger->balance_after)->toString(),
            'frozen_before' => Money::from($ledger->frozen_before)->toString(),
            'frozen_after' => Money::from($ledger->frozen_after)->toString(),
            'type' => $ledger->type,
            'source_type' => $ledger->source_type,
            'source_id' => $ledger->source_id === null ? null : (int) $ledger->source_id,
            'remark' => $ledger->remark,
            'admin_id' => $ledger->admin_id === null ? null : (int) $ledger->admin_id,
            'create_time' => $ledger->create_time,
        ];
    }
}
