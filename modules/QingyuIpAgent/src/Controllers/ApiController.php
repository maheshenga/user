<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
use App\Modules\ModuleApiException;
use App\Modules\ModuleApiRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\QingyuIpAgent\Services\ClientApiService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ApiController extends Controller
{
    public function bootstrap(ClientApiService $service): JsonResponse
    {
        $data = $service->bootstrap();
        unset($data['csrf_token']);

        return $this->success($data);
    }

    public function sampleAudio(): BinaryFileResponse
    {
        $file = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.'local-member-sample.mp3';
        abort_unless(is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function activate(Request $request, ClientApiService $service): JsonResponse
    {
        return $this->run(
            $request,
            'activation.redeem',
            fn (UserAccount $user): array => $service->activateForUser($user, $request->all(), $request->ip())
        );
    }

    public function parseContent(Request $request, ClientApiService $service): JsonResponse
    {
        return $this->run(
            $request,
            'content.parse',
            fn (UserAccount $user): array => $service->parseContentForUser($user, $request->all())
        );
    }

    public function rewrite(Request $request, ClientApiService $service): JsonResponse
    {
        return $this->run(
            $request,
            'content.rewrite',
            fn (UserAccount $user): array => $service->rewriteForUser($user, $request->all())
        );
    }

    private function run(Request $request, string $operation, callable $callback): JsonResponse
    {
        $requests = app(ModuleApiRequestService::class);
        $requestId = '';
        try {
            $requestId = $requests->requestId($request->header('X-Request-ID', $request->input('request_id')));
            $user = $request->user();
            if (! $user instanceof UserAccount) {
                return $this->error('请先登录。', 401, 'unauthenticated', $requestId);
            }

            $result = $requests->execute(
                'qingyu_ip_agent',
                (int) $user->id,
                $operation,
                $requestId,
                $request->except('request_id'),
                fn (): array => $this->invoke($operation, $user, $callback)
            );

            return $this->success($result['data'], 'ok', $result['request_id']);
        } catch (ModuleApiException $exception) {
            return $this->error($exception->getMessage(), $exception->httpStatus(), $exception->errorCode(), $requestId);
        } catch (Throwable) {
            return $this->error('模块服务暂时不可用，请稍后重试。', 500, 'module_unavailable', $requestId);
        }
    }

    private function invoke(string $operation, UserAccount $user, callable $callback): array
    {
        try {
            return $callback($user);
        } catch (InvalidArgumentException $exception) {
            [$status, $code] = match ($operation) {
                'activation.redeem' => [422, 'activation_invalid'],
                'content.parse' => [422, 'content_parse_failed'],
                'content.rewrite' => str_contains($exception->getMessage(), 'VIP')
                    ? [403, 'vip_required']
                    : [422, 'content_rewrite_failed'],
                default => [422, 'module_request_failed'],
            };

            throw new ModuleApiException($exception->getMessage(), $status, $code, $exception);
        }
    }

    private function success(array $data = [], string $message = 'ok', ?string $requestId = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 0,
            'message' => $message,
            'request_id' => $requestId,
            'data' => $data,
        ]);
    }

    private function error(string $message, int $status, string $code, ?string $requestId = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
            'data' => [],
        ], $status);
    }
}
