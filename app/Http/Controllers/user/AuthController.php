<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Http\JumpTrait;
use App\User\PasswordResetService;
use App\User\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AuthController extends Controller
{
    use JumpTrait;

    public function register(UserAuthService $auth): JsonResponse
    {
        $payload = request()->only(['mobile', 'email', 'password', 'invite_code']);
        $validator = Validator::make($payload, [
            'mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:180',
            'password' => 'required|string|min:6|max:72',
            'invite_code' => 'nullable|string|max:40',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('注册成功', $auth->register($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function login(UserAuthService $auth): JsonResponse
    {
        $payload = request()->only(['account', 'password']);
        $validator = Validator::make($payload, [
            'account' => 'required|string|max:180',
            'password' => 'required|string|max:72',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('登录成功', $auth->login($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function forgotPassword(PasswordResetService $passwords): JsonResponse
    {
        $payload = request()->only(['account']);
        $validator = Validator::make($payload, [
            'account' => 'required|string|max:180',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('Password reset accepted.', $passwords->requestReset($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function resetPassword(PasswordResetService $passwords): JsonResponse
    {
        $payload = request()->only(['account', 'password', 'token', 'code']);
        $validator = Validator::make($payload, [
            'account' => 'required|string|max:180',
            'password' => 'required|string|min:6|max:72',
            'token' => 'nullable|string',
            'code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('Password reset completed.', $passwords->resetPassword($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function logout(UserAuthService $auth): JsonResponse
    {
        $auth->logout();

        return $this->success('退出登录成功');
    }
}
