<?php

namespace App\Contracts\Modules;

interface BalanceGateway
{
    public function summary(int $userId): array;

    public function ledger(int $userId, int $limit = 20): array;

    public function credit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array;

    public function debit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark): array;
}
