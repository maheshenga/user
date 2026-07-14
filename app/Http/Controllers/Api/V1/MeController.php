<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Modules\BalanceGateway;
use App\Contracts\Modules\InvitationGateway;
use App\Contracts\Modules\VipGateway;
use App\Models\UserAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends ApiController
{
    public function vip(Request $request, VipGateway $vip): JsonResponse
    {
        return $this->success($vip->summary($this->userId($request)), 'VIP 概览。');
    }

    public function invitations(Request $request, InvitationGateway $invitations): JsonResponse
    {
        return $this->success($invitations->summary($this->userId($request)), '邀请概览。');
    }

    public function balance(Request $request, BalanceGateway $balance): JsonResponse
    {
        return $this->success($balance->summary($this->userId($request)), '余额概览。');
    }

    public function ledger(Request $request, BalanceGateway $balance): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        return $this->success($balance->ledger($this->userId($request), $limit), '余额流水。');
    }

    private function userId(Request $request): int
    {
        /** @var UserAccount $user */
        $user = $request->user();

        return (int) $user->id;
    }
}
