<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\User\WithdrawalService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class WithdrawalController extends Controller
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
