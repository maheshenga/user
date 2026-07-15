<?php

namespace App\Modules\Host;

use App\Contracts\Modules\BalanceGateway;
use App\User\BalanceLedgerService;
use App\Modules\ModuleCapabilityPolicy;
use App\Modules\ModuleIdentity;

final class HostBalanceGateway implements BalanceGateway
{
    public function __construct(
        private readonly BalanceLedgerService $balance,
        private readonly ModuleCapabilityPolicy $capabilities,
    ) {}

    public function summary(int $userId): array
    {
        $this->capabilities->authorize('balance:read');

        return $this->balance->summary($userId);
    }

    public function ledger(int $userId, int $limit = 20): array
    {
        $this->capabilities->authorize('balance:read');

        return $this->balance->ledger($userId, $limit);
    }

    public function credit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array
    {
        $identity = $this->capabilities->authorize('balance:write');

        return $this->balance->credit($userId, $amount, $type, $this->sourceType($identity, $sourceType), $sourceId, $remark);
    }

    public function debit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array
    {
        $identity = $this->capabilities->authorize('balance:write');

        return $this->balance->debit($userId, $amount, $type, $this->sourceType($identity, $sourceType), $sourceId, $remark);
    }

    private function sourceType(ModuleIdentity $identity, ?string $sourceType): ?string
    {
        if ($identity->isHost()) {
            return $sourceType;
        }

        $sourceType = preg_replace('/[^a-z0-9._-]+/i', '_', trim((string) $sourceType)) ?: 'operation';

        return mb_substr('module:'.$identity->name.':'.$sourceType, 0, 80);
    }
}
