<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Middleware\LegacyModuleClientDeprecation;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\MiddlewareAnnotation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\QingyuIpAgent\Services\ClientApiService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[ControllerAnnotation(title: '轻语桌面端接入', auth: false)]
class ClientController extends Controller
{
    private const MODULE = 'qingyu_ip_agent';

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function bootstrap(): JsonResponse
    {
        return $this->legacyJsonResponse(
            'bootstrap',
            fn (): JsonResponse => $this->clientSuccess(app(ClientApiService::class)->bootstrap())
        );
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function register(): JsonResponse
    {
        return $this->runClientAction('register', fn (ClientApiService $service): array => $service->register(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function login(): JsonResponse
    {
        return $this->runClientAction('login', fn (ClientApiService $service): array => $service->login(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function profile(): JsonResponse
    {
        return $this->runClientAction('profile', fn (ClientApiService $service): array => $service->profile());
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function activate(): JsonResponse
    {
        return $this->runClientAction('activate', fn (ClientApiService $service): array => $service->activate(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function parseContent(): JsonResponse
    {
        return $this->runClientAction('parseContent', fn (ClientApiService $service): array => $service->parseContent(request()->all()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function rewrite(): JsonResponse
    {
        return $this->runClientAction('rewrite', fn (ClientApiService $service): array => $service->rewrite(request()->all()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function sampleAudio(): Response
    {
        return $this->legacyResponse('sampleAudio', function (): Response {
            $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.'local-member-sample.mp3';
            abort_unless(is_file($file), 404);

            return response()->file($file, [
                'Content-Type' => 'audio/mpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        });
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function sendResetCode(): JsonResponse
    {
        return $this->runClientAction('sendResetCode', fn (ClientApiService $service): array => $service->sendResetCode(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function resetPassword(): JsonResponse
    {
        return $this->runClientAction('resetPassword', fn (ClientApiService $service): array => $service->resetPassword(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function logout(): JsonResponse
    {
        return $this->runClientAction('logout', fn (ClientApiService $service): array => $service->logout());
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function updateProfile(): JsonResponse
    {
        return $this->runClientAction('updateProfile', fn (ClientApiService $service): array => $service->unsupported('updateProfile'));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function updatePassword(): JsonResponse
    {
        return $this->runClientAction('updatePassword', fn (ClientApiService $service): array => $service->unsupported('updatePassword'));
    }

    private function runClientAction(string $action, callable $callback): JsonResponse
    {
        return $this->legacyJsonResponse($action, function () use ($callback): JsonResponse {
            try {
                return $this->clientSuccess($callback(app(ClientApiService::class)));
            } catch (Throwable $exception) {
                return $this->clientError($exception->getMessage());
            }
        });
    }

    private function legacyJsonResponse(string $action, Closure $next): JsonResponse
    {
        $response = $this->legacyResponse($action, $next);
        if (! $response instanceof JsonResponse) {
            throw new RuntimeException('旧客户端 JSON 响应类型无效。');
        }

        return $response;
    }

    private function legacyResponse(string $action, Closure $next): Response
    {
        $successor = (string) config(
            'modules.legacy_client_routes.'.self::MODULE.'.successors.'.$action,
            '/api/v1'
        );

        return app(LegacyModuleClientDeprecation::class)->handle(
            request(),
            fn (Request $request): Response => $next(),
            self::MODULE,
            $successor,
            $action
        );
    }

    private function clientSuccess(array $data = [], string $message = 'ok'): JsonResponse
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

    private function clientError(string $message): JsonResponse
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
