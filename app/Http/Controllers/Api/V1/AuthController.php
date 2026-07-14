<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UserAccount;
use App\Models\UserApiSession;
use App\User\PasswordResetService;
use App\User\UserApiException;
use App\User\UserApiProfileService;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    public function register(
        Request $request,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $payload = $validator->validated();
        $payload['source_module'] = $payload['module'];

        try {
            $registered = $auth->register($payload, $request->ip());
            $user = UserAccount::query()->findOrFail((int) $registered['user']['id']);
            $tokenBundle = $tokens->issue(
                $user,
                $payload['module'],
                $this->device($payload),
                $request->ip(),
                (string) $request->userAgent()
            );

            return $this->success(
                $profiles->payload($user) + ['tokens' => $tokenBundle],
                '注册成功。',
                201
            );
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            $duplicate = str_contains($exception->getMessage(), '已存在');

            return $this->error(
                $exception->getMessage(),
                $duplicate ? 409 : 422,
                $duplicate ? 'account_exists' : 'registration_failed'
            );
        }
    }

    public function login(
        Request $request,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(false));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $payload = $validator->validated();

        try {
            $authenticated = $auth->authenticate($payload, $request->ip());
            $user = UserAccount::query()->findOrFail((int) $authenticated['user']['id']);
            $tokenBundle = $tokens->issue(
                $user,
                $payload['module'],
                $this->device($payload),
                $request->ip(),
                (string) $request->userAgent()
            );

            return $this->success($profiles->payload($user) + ['tokens' => $tokenBundle], '登录成功。');
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, '15 分钟')) {
                return $this->error($message, 429, 'login_rate_limited');
            }
            if (str_contains($message, '不可登录')) {
                return $this->error($message, 403, 'account_unavailable');
            }

            return $this->error($message, 401, 'invalid_credentials');
        }
    }

    public function refresh(
        Request $request,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string|max:200',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $tokenBundle = $tokens->rotate(
                $validator->validated()['refresh_token'],
                $request->ip(),
                (string) $request->userAgent()
            );
            $session = UserApiSession::query()->findOrFail((int) $tokenBundle['session_id']);
            $user = UserAccount::query()->findOrFail((int) $session->user_id);

            return $this->success($profiles->payload($user) + ['tokens' => $tokenBundle], '令牌已刷新。');
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        }
    }

    public function profile(Request $request, UserApiProfileService $profiles): JsonResponse
    {
        /** @var UserAccount $user */
        $user = $request->user();

        return $this->success($profiles->payload($user), '用户资料。');
    }

    public function logout(Request $request, UserApiTokenService $tokens): JsonResponse
    {
        /** @var UserAccount $user */
        $user = $request->user();
        $accessToken = $user->currentAccessToken();
        $tokens->revoke($user, $accessToken instanceof PersonalAccessToken ? (int) $accessToken->id : null);

        return $this->success(['logged_out' => true], '退出登录成功。');
    }

    public function forgotPassword(Request $request, PasswordResetService $passwords): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account' => 'required|string|max:180',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $data = $passwords->requestReset($validator->validated(), $request->ip());

            return $this->success($data, '重置申请已受理。');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, 'password_reset_failed');
        }
    }

    public function resetPassword(Request $request, PasswordResetService $passwords): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account' => 'required|string|max:180',
            'password' => 'required|string|min:6|max:72',
            'token' => 'nullable|string|max:200',
            'code' => 'nullable|string|max:20',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $data = $passwords->resetPassword($validator->validated(), $request->ip());

            return $this->success($data, '密码重置成功。');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, 'password_reset_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialRules(bool $registration): array
    {
        $rules = [
            'password' => 'required|string|min:6|max:72',
            'module' => 'required|string|max:80',
            'device_id' => 'required|string|max:128',
            'device_name' => 'nullable|string|max:160',
        ];

        if ($registration) {
            return $rules + [
                'mobile' => 'nullable|string|max:32|required_without:email',
                'email' => 'nullable|email|max:180|required_without:mobile',
                'invite_code' => 'nullable|string|max:40',
            ];
        }

        return $rules + [
            'account' => 'required|string|max:180',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{device_id:string,device_name:mixed}
     */
    private function device(array $payload): array
    {
        return [
            'device_id' => (string) $payload['device_id'],
            'device_name' => $payload['device_name'] ?? null,
        ];
    }
}
