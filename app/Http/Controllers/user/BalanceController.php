<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\User\BalanceLedgerService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class BalanceController extends Controller
{
    public function summary(BalanceLedgerService $balance): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('User login required.');
        }

        try {
            return $this->jsonSuccess('Balance summary', $balance->summary($userId));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function ledger(BalanceLedgerService $balance): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('User login required.');
        }

        $limit = max(1, min(100, (int) request()->query('limit', 20)));

        try {
            return $this->jsonSuccess('Balance ledger', $balance->ledger($userId, $limit));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    private function currentUserId(): ?int
    {
        $id = session('user.id');

        return $id === null ? null : (int) $id;
    }

    private function jsonSuccess(string $message, array $data): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => $message,
            'data' => $data,
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    private function jsonError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => $message,
            'data' => [],
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }
}
