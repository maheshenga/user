<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\User\BalanceLedgerService;
use App\User\InviteService;
use App\User\VipService;
use App\User\WithdrawalService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class DashboardController extends Controller
{
    public function summary(
        VipService $vip,
        BalanceLedgerService $balance,
        WithdrawalService $withdrawals,
        InviteService $invites
    ): JsonResponse {
        $user = session('user');
        if (! is_array($user) || empty($user['id'])) {
            return $this->jsonError('请先登录。');
        }

        unset($user['password']);
        $userId = (int) $user['id'];

        try {
            return $this->jsonSuccess('仪表盘概览', [
                'user' => $user,
                'vip' => $vip->summary($userId),
                'balance' => $balance->summary($userId),
                'ledger' => $balance->ledger($userId, 20),
                'withdrawals' => $withdrawals->listForUser($userId, 20),
                'invite' => $invites->inviteSummary($userId),
                'inviteRecords' => $invites->inviteRecords($userId),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
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
