<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserBalanceLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BalanceReconciliationService
{
    public function inspect(?int $userId = null, int $limit = 1000): array
    {
        if ($userId !== null && $userId <= 0) {
            throw new InvalidArgumentException('用户 ID 无效。');
        }

        $limit = max(1, min(10_000, $limit));

        return DB::transaction(fn (): array => $this->inspectSnapshot($userId, $limit));
    }

    private function inspectSnapshot(?int $userId, int $limit): array
    {
        $query = UserAccount::query()->orderBy('id');
        if ($userId !== null) {
            $query->whereKey($userId);
        }

        $users = $query->limit($limit)->get();
        $issues = [];
        foreach ($users as $user) {
            array_push($issues, ...$this->inspectUser($user));
        }

        return [
            'checked_users' => $users->count(),
            'issue_count' => count($issues),
            'issues' => $issues,
        ];
    }

    private function inspectUser(UserAccount $user): array
    {
        $ledgers = UserBalanceLedger::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();
        $accountAvailable = Money::from($user->available_balance ?? 0);
        $accountFrozen = Money::from($user->frozen_balance ?? 0);

        if ($ledgers->isEmpty()) {
            if ($accountAvailable->isZero() && $accountFrozen->isZero()) {
                return [];
            }

            return [$this->issue(
                (int) $user->id,
                'missing_ledger',
                null,
                '0.00/0.00',
                $this->snapshot($accountAvailable, $accountFrozen)
            )];
        }

        $issues = [];
        $previousAvailable = null;
        $previousFrozen = null;
        foreach ($ledgers as $ledger) {
            $beforeAvailable = Money::from($ledger->balance_before);
            $beforeFrozen = Money::from($ledger->frozen_before);
            if (
                $previousAvailable !== null
                && ($beforeAvailable->compareTo($previousAvailable) !== 0 || $beforeFrozen->compareTo($previousFrozen) !== 0)
            ) {
                $issues[] = $this->issue(
                    (int) $user->id,
                    'ledger_continuity_mismatch',
                    (int) $ledger->id,
                    $this->snapshot($previousAvailable, $previousFrozen),
                    $this->snapshot($beforeAvailable, $beforeFrozen)
                );
            }

            $previousAvailable = Money::from($ledger->balance_after);
            $previousFrozen = Money::from($ledger->frozen_after);
        }

        if (
            $previousAvailable->compareTo($accountAvailable) !== 0
            || $previousFrozen->compareTo($accountFrozen) !== 0
        ) {
            $issues[] = $this->issue(
                (int) $user->id,
                'account_snapshot_mismatch',
                (int) $ledgers->last()->id,
                $this->snapshot($previousAvailable, $previousFrozen),
                $this->snapshot($accountAvailable, $accountFrozen)
            );
        }

        return $issues;
    }

    private function snapshot(Money $available, Money $frozen): string
    {
        return $available->toString().'/'.$frozen->toString();
    }

    private function issue(int $userId, string $code, ?int $ledgerId, string $expected, string $actual): array
    {
        return [
            'user_id' => $userId,
            'code' => $code,
            'ledger_id' => $ledgerId,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}
