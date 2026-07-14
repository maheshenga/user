<?php

namespace App\Http\Middleware;

use App\Models\UserAccount;
use App\Models\UserApiSession;
use App\User\ModuleApiPolicy;
use App\User\UserApiException;
use App\User\UserApiTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveApiModule
{
    public function __construct(
        private readonly ModuleApiPolicy $policy,
        private readonly UserApiTokenService $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $accessToken = $user instanceof UserAccount ? $user->currentAccessToken() : null;
        if (! $user instanceof UserAccount || ! $accessToken instanceof PersonalAccessToken) {
            return $this->error('请先登录。', 401, 'unauthenticated');
        }

        $session = UserApiSession::query()->where('access_token_id', $accessToken->id)->first();
        if ($session === null || $session->revoked_at !== null) {
            $accessToken->delete();

            return $this->error('模块设备会话不存在。', 401, 'module_session_missing');
        }

        try {
            $this->policy->assertUserAccess((string) $session->module, $user);
        } catch (UserApiException $exception) {
            $this->tokens->revoke($user, (int) $accessToken->id);

            return $this->error($exception->getMessage(), $exception->httpStatus(), $exception->errorCode());
        }

        $request->attributes->set('module_api_session', $session);

        return $next($request);
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => [],
        ], $status);
    }
}
