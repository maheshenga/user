<?php

namespace App\Http\Controllers\user;

use App\User\BalanceLedgerService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class BalanceController extends UserApiController
{
    public function summary(BalanceLedgerService $balance): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        try {
            return $this->jsonSuccess('余额概览', $balance->summary($userId));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function ledger(BalanceLedgerService $balance): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        $limit = max(1, min(100, (int) request()->query('limit', 20)));

        try {
            return $this->jsonSuccess('余额流水', $balance->ledger($userId, $limit));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

}
