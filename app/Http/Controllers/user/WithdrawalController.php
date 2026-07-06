<?php

namespace App\Http\Controllers\user;

use App\User\WithdrawalService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class WithdrawalController extends UserApiController
{
    public function request(WithdrawalService $withdrawals): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        $account = request()->input('account', []);
        if (! is_array($account)) {
            $account = [];
        }

        try {
            return $this->jsonSuccess('提现申请已提交', $withdrawals->request(
                $userId,
                request()->input('amount', 0),
                $account,
                request()->ip() ?? ''
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

    public function index(WithdrawalService $withdrawals): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        $limit = max(1, min(100, (int) request()->query('limit', 20)));

        return $this->jsonSuccess('提现记录', $withdrawals->listForUser($userId, $limit));
    }

}
