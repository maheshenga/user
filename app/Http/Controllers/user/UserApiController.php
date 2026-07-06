<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
use App\User\UserAccountStatus;
use Illuminate\Http\JsonResponse;

abstract class UserApiController extends Controller
{
    protected function currentUser(): ?array
    {
        $sessionUser = session('user');

        if (! is_array($sessionUser) || empty($sessionUser['id'])) {
            return null;
        }

        $account = UserAccount::query()->find((int) $sessionUser['id']);

        if ($account === null || ! UserAccountStatus::canLogin((string) $account->status)) {
            session()->forget('user');

            return null;
        }

        $user = [
            'id' => $account->id,
            'mobile' => $account->mobile,
            'email' => $account->email,
            'nickname' => $account->nickname,
            'status' => $account->status,
        ];

        session(['user' => $user]);

        return $user;
    }

    protected function currentUserId(): ?int
    {
        $user = $this->currentUser();

        return $user === null ? null : (int) $user['id'];
    }

    protected function jsonSuccess(string $message, array $data = []): JsonResponse
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

    protected function jsonError(string $message): JsonResponse
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
