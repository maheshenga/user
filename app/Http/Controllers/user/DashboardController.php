<?php

namespace App\Http\Controllers\user;

use App\User\BalanceLedgerService;
use App\User\InviteService;
use App\User\VipService;
use App\User\WithdrawalService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class DashboardController extends UserApiController
{
    public function summary(
        VipService $vip,
        BalanceLedgerService $balance,
        WithdrawalService $withdrawals,
        InviteService $invites
    ): JsonResponse {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->jsonError('请先登录。');
        }

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

}
