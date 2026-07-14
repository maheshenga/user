<?php

namespace App\Http\Middleware;

use App\Models\UserAccount;
use App\User\UserAccountStatus;
use App\User\UserApiTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveApiUser
{
    public function __construct(private readonly UserApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof UserAccount) {
            return $this->error('请先登录。', 401, 'unauthenticated');
        }

        if (! UserAccountStatus::canLogin((string) $user->status)) {
            $this->tokens->revokeAll($user);

            return $this->error('账号当前不可登录。', 403, 'account_unavailable');
        }

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
