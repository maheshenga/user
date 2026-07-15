<?php

namespace App\Http\Middleware;

use App\Modules\ModuleRuntimeEligibility;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LegacyModuleClientDeprecation
{
    public function __construct(private readonly ModuleRuntimeEligibility $eligibility) {}

    public function handle(
        Request $request,
        Closure $next,
        string $module,
        string $successor,
        ?string $action = null
    ): Response {
        if ($request->attributes->get('legacy_module_client_deprecation_applied') === true) {
            return $next($request);
        }
        $request->attributes->set('legacy_module_client_deprecation_applied', true);

        try {
            $this->eligibility->assertEligible($module);
        } catch (Throwable) {
            abort(404);
        }

        $successor = $this->successor($successor);
        $settings = (array) config("modules.legacy_client_routes.{$module}", []);
        $enabled = (bool) ($settings['enabled'] ?? true);
        $sunset = $this->headerValue((string) ($settings['sunset'] ?? ''));
        $action = $action ?: (string) ($request->route()?->getActionMethod() ?? 'unknown');

        try {
            Log::notice('module_legacy_client_request', [
                'module' => $module,
                'action' => $action,
                'result' => $enabled ? 'deprecated' : 'blocked',
                'successor' => $successor,
                'sunset' => $sunset,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (Throwable) {
            // Telemetry must not make the compatibility route unavailable.
        }

        $response = $enabled
            ? $next($request)
            : $this->gone($module, $successor);

        $response->headers->set('Deprecation', 'true');
        if ($sunset !== '') {
            $response->headers->set('Sunset', $sunset);
        }
        $response->headers->set('Link', '<'.url($successor).'>; rel="successor-version"');

        return $response;
    }

    private function gone(string $module, string $successor): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => '旧版模块客户端接口已停用，请升级客户端并迁移到版本化 API。',
            'data' => [
                'code' => 'legacy_client_disabled',
                'module' => $module,
                'successor' => $successor,
            ],
            'url' => url($successor),
            'wait' => 0,
            '__token__' => csrf_token(),
        ], 410, ['Cache-Control' => 'no-store, private']);
    }

    private function successor(string $successor): string
    {
        $successor = '/'.ltrim($this->headerValue($successor), '/');

        return str_starts_with($successor, '/api/v1/') ? $successor : '/api/v1';
    }

    private function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
