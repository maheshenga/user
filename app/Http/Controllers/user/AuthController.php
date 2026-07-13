<?php

namespace App\Http\Controllers\user;

use App\Http\JumpTrait;
use App\User\PasswordResetService;
use App\User\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AuthController extends UserApiController
{
    use JumpTrait;

    public function register(UserAuthService $auth): JsonResponse
    {
        $payload = request()->only(['mobile', 'email', 'password', 'invite_code', 'source_module']);
        $validator = Validator::make($payload, [
            'mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:180',
            'password' => 'required|string|min:6|max:72',
            'invite_code' => 'nullable|string|max:40',
            'source_module' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], [
            'mobile.string' => '手机号格式不正确。',
            'mobile.max' => '手机号不能超过 32 个字符。',
            'email.email' => '邮箱格式不正确。',
            'email.max' => '邮箱不能超过 180 个字符。',
            'password.required' => '密码不能为空。',
            'password.string' => '密码格式不正确。',
            'password.min' => '密码至少需要 6 位。',
            'password.max' => '密码不能超过 72 位。',
            'invite_code.string' => '邀请码格式不正确。',
            'invite_code.max' => '邀请码不能超过 40 个字符。',
            'source_module.string' => '所属模块格式不正确。',
            'source_module.max' => '所属模块不能超过 80 个字符。',
            'source_module.regex' => '所属模块只能包含字母、数字、点、横线和下划线。',
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
        ], [
            'account.required' => '账号不能为空。',
            'account.string' => '账号格式不正确。',
            'account.max' => '账号不能超过 180 个字符。',
            'password.required' => '密码不能为空。',
            'password.string' => '密码格式不正确。',
            'password.max' => '密码不能超过 72 位。',
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
        ], [
            'account.required' => '账号不能为空。',
            'account.string' => '账号格式不正确。',
            'account.max' => '账号不能超过 180 个字符。',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('重置申请已受理。', $passwords->requestReset($payload, request()->ip()));
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
        ], [
            'account.required' => '账号不能为空。',
            'account.string' => '账号格式不正确。',
            'account.max' => '账号不能超过 180 个字符。',
            'password.required' => '密码不能为空。',
            'password.string' => '密码格式不正确。',
            'password.min' => '密码至少需要 6 位。',
            'password.max' => '密码不能超过 72 位。',
            'token.string' => '重置令牌格式不正确。',
            'code.string' => '验证码格式不正确。',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('密码重置成功。', $passwords->resetPassword($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function session(): JsonResponse
    {
        $user = $this->currentUser();

        if ($user === null) {
            return $this->jsonError('请先登录。');
        }

        return $this->jsonSuccess('用户会话', [
            'user' => $user,
        ]);
    }

    public function logout(UserAuthService $auth): JsonResponse
    {
        $auth->logout();

        return $this->success('退出登录成功');
    }
}
