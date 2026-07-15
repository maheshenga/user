<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UserAccount;
use App\Models\UserApiSession;
use App\User\ModuleApiPolicy;
use App\User\ModuleRegistrationTicketService;
use App\User\PasswordResetService;
use App\User\UserApiException;
use App\User\UserApiProfileService;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use App\User\UserModuleMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    public function register(
        Request $request,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        ModuleApiPolicy $modules,
        ModuleRegistrationTicketService $tickets
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $payload = $validator->validated();

        try {
            return DB::transaction(function () use ($payload, $request, $auth, $tokens, $profiles, $modules, $tickets): JsonResponse {
                $claims = $tickets->consume((string) $payload['registration_ticket']);
                if (is_string($claims['invite_code'] ?? null)) {
                    $payload['invite_code'] = $claims['invite_code'];
                }

                return $this->registerForResolvedModule(
                    $request,
                    $payload,
                    (string) $claims['module'],
                    $auth,
                    $tokens,
                    $profiles,
                    $modules
                );
            });
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->registrationException($exception);
        }
    }

    public function registerForModule(
        Request $request,
        string $module,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        ModuleApiPolicy $modules
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(true, true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            return $this->registerForResolvedModule(
                $request,
                $validator->validated(),
                $module,
                $auth,
                $tokens,
                $profiles,
                $modules
            );
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->registrationException($exception);
        }
    }

    public function issueRegistrationTicket(
        Request $request,
        string $module,
        ModuleApiPolicy $modules,
        ModuleRegistrationTicketService $tickets
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'invite_code' => 'nullable|string|max:40',
            'campaign' => 'nullable|string|max:120',
            'expires_in' => 'nullable|integer|min:60|max:1800',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $modules->assertAvailable($module);
            $payload = $validator->validated();
            $expiresAt = now()->addSeconds((int) ($payload['expires_in'] ?? 300));
            $claims = array_filter([
                'invite_code' => $payload['invite_code'] ?? null,
                'campaign' => $payload['campaign'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            $ticket = $tickets->issue($module, $claims, $expiresAt);

            return $this->success([
                'ticket' => $ticket,
                'expires_at' => $expiresAt->toIso8601String(),
            ], '注册票据已签发。', 201);
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        }
    }

    public function login(
        Request $request,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        ModuleApiPolicy $modules,
        UserModuleMembershipService $memberships
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(false));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $payload = $validator->validated();

        try {
            $module = (string) $payload['module'];
            $modules->assertAvailable($module);

            return $this->loginForResolvedModule(
                $request,
                $payload,
                $module,
                false,
                $auth,
                $tokens,
                $profiles,
                $memberships
            );
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->loginException($exception);
        }
    }

    public function loginForModule(
        Request $request,
        string $module,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        ModuleApiPolicy $modules,
        UserModuleMembershipService $memberships
    ): JsonResponse {
        $validator = Validator::make($request->all(), $this->credentialRules(false, true));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $modules->assertAvailable($module);
            $result = DB::transaction(function () use ($request, $validator, $module, $auth, $tokens, $profiles, $memberships): array {
                try {
                    return ['response' => $this->loginForResolvedModule(
                        $request,
                        $validator->validated(),
                        $module,
                        true,
                        $auth,
                        $tokens,
                        $profiles,
                        $memberships
                    )];
                } catch (InvalidArgumentException $exception) {
                    return ['error' => $exception];
                }
            });
            if (($result['error'] ?? null) instanceof InvalidArgumentException) {
                throw $result['error'];
            }

            return $result['response'];
        } catch (UserApiException $exception) {
            return $this->apiException($exception);
        } catch (InvalidArgumentException $exception) {
            return $this->loginException($exception);
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
    private function credentialRules(bool $registration, bool $routeBound = false): array
    {
        $rules = [
            'password' => 'required|string|min:6|max:72',
            'device_id' => 'required|string|max:128',
            'device_name' => 'nullable|string|max:160',
        ];

        if ($registration) {
            $rules += [
                'mobile' => 'nullable|string|max:32|required_without:email',
                'email' => 'nullable|email|max:180|required_without:mobile',
                'invite_code' => 'nullable|string|max:40',
            ];

            return $routeBound ? $rules : $rules + [
                'registration_ticket' => 'required|string|max:8192',
            ];
        }

        $rules += [
            'account' => 'required|string|max:180',
        ];

        return $routeBound ? $rules : $rules + [
            'module' => 'required|string|max:80',
        ];
    }

    private function registerForResolvedModule(
        Request $request,
        array $payload,
        string $module,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        ModuleApiPolicy $modules
    ): JsonResponse {
        $modules->assertAvailable($module);
        $registered = $auth->registerWithToken(
            $payload,
            $request->ip(),
            $module,
            fn (UserAccount $user): array => $tokens->issue(
                $user,
                $module,
                $this->device($payload),
                $request->ip(),
                (string) $request->userAgent()
            )
        );
        $user = UserAccount::query()->findOrFail((int) $registered['user']['id']);

        return $this->success(
            $profiles->payload($user) + ['tokens' => $registered['tokens']],
            '注册成功。',
            201
        );
    }

    private function loginForResolvedModule(
        Request $request,
        array $payload,
        string $module,
        bool $joinModule,
        UserAuthService $auth,
        UserApiTokenService $tokens,
        UserApiProfileService $profiles,
        UserModuleMembershipService $memberships
    ): JsonResponse {
        $authenticated = $auth->authenticate($payload, $request->ip());
        $user = UserAccount::query()->findOrFail((int) $authenticated['user']['id']);
        if ($joinModule) {
            $memberships->join((int) $user->id, $module, 'route_login');
        }
        $tokenBundle = $tokens->issue(
            $user,
            $module,
            $this->device($payload),
            $request->ip(),
            (string) $request->userAgent()
        );

        return $this->success($profiles->payload($user) + ['tokens' => $tokenBundle], '登录成功。');
    }

    private function registrationException(InvalidArgumentException $exception): JsonResponse
    {
        $duplicate = str_contains($exception->getMessage(), '已存在');

        return $this->error(
            $exception->getMessage(),
            $duplicate ? 409 : 422,
            $duplicate ? 'account_exists' : 'registration_failed'
        );
    }

    private function loginException(InvalidArgumentException $exception): JsonResponse
    {
        $message = $exception->getMessage();
        if (str_contains($message, '15 分钟')) {
            return $this->error($message, 429, 'login_rate_limited');
        }
        if (str_contains($message, '不可登录')) {
            return $this->error($message, 403, 'account_unavailable');
        }

        return $this->error($message, 401, 'invalid_credentials');
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
