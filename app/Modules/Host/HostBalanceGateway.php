<?php

namespace App\Modules\Host;

use App\Contracts\Modules\BalanceGateway;
use App\User\BalanceLedgerService;

final class HostBalanceGateway implements BalanceGateway
{
    public function __construct(private readonly BalanceLedgerService $balance) {}

    public function summary(int $userId): array
    {
        return $this->balance->summary($userId);
    }

    public function ledger(int $userId, int $limit = 20): array
    {
        return $this->balance->ledger($userId, $limit);
    }

    public function credit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array
    {
        return $this->balance->credit($userId, $amount, $type, $sourceType, $sourceId, $remark);
    }

    public function debit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array
    {
        return $this->balance->debit($userId, $amount, $type, $sourceType, $sourceId, $remark);
    }
}
