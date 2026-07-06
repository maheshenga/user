<?php

namespace App\Http\Controllers\user;

use App\User\VipService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class VipController extends UserApiController
{
    public function summary(VipService $vip): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        try {
            return $this->jsonSuccess('VIP 概览', $vip->summary($userId));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage());
        }
    }

}
