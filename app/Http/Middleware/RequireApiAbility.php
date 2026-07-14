<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'tokenCan') || ! $user->tokenCan($ability)) {
            return response()->json([
                'success' => false,
                'code' => 'ability_denied',
                'message' => '当前令牌没有执行该操作的权限。',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }
}
