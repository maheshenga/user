<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\MiddlewareAnnotation;
use Illuminate\Http\JsonResponse;
use Modules\QingyuIpAgent\Services\ClientApiService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

#[ControllerAnnotation(title: '轻语桌面端接入', auth: false)]
class ClientController extends Controller
{
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function bootstrap(): JsonResponse
    {
        return $this->clientSuccess(app(ClientApiService::class)->bootstrap());
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function register(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->register(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function login(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->login(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function profile(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->profile());
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function activate(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->activate(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function parseContent(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->parseContent(request()->all()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function rewrite(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->rewrite(request()->all()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function sampleAudio(): BinaryFileResponse
    {
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.'local-member-sample.mp3';
        abort_unless(is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function sendResetCode(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->sendResetCode(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function resetPassword(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->resetPassword(request()->all(), request()->ip()));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function logout(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->logout());
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function updateProfile(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->unsupported('updateProfile'));
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOG)]
    public function updatePassword(): JsonResponse
    {
        return $this->runClientAction(fn (ClientApiService $service): array => $service->unsupported('updatePassword'));
    }

    private function runClientAction(callable $callback): JsonResponse
    {
        try {
            return $this->clientSuccess($callback(app(ClientApiService::class)));
        } catch (Throwable $exception) {
            return $this->clientError($exception->getMessage());
        }
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
