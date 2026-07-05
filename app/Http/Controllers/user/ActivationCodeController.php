<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Http\JumpTrait;
use App\User\ActivationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ActivationCodeController extends Controller
{
    use JumpTrait;

    public function redeem(ActivationCodeService $activationCodes): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->error('User login required.');
        }

        $payload = request()->only(['code']);
        $validator = Validator::make($payload, [
            'code' => 'required|string|max:80',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('Activation code redeemed.', $activationCodes->redeem($payload, $userId, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    private function currentUserId(): ?int
    {
        $id = session('user.id');

        return $id === null ? null : (int) $id;
    }
}
