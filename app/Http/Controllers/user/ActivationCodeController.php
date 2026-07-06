<?php

namespace App\Http\Controllers\user;

use App\Http\JumpTrait;
use App\User\ActivationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ActivationCodeController extends UserApiController
{
    use JumpTrait;

    public function redeem(ActivationCodeService $activationCodes): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->error('请先登录。');
        }

        $payload = request()->only(['code']);
        $validator = Validator::make($payload, [
            'code' => 'required|string|max:80',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('激活码兑换成功。', $activationCodes->redeem($payload, $userId, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

}
