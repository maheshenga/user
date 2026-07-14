<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
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
            fn (UserAccount $user): array => $service->activateForUser($user, $request->all(), $request->ip())
        );
    }

    public function parseContent(Request $request, ClientApiService $service): JsonResponse
    {
        return $this->run(
            $request,
            fn (UserAccount $user): array => $service->parseContentForUser($user, $request->all())
        );
    }

    public function rewrite(Request $request, ClientApiService $service): JsonResponse
    {
        return $this->run(
            $request,
            fn (UserAccount $user): array => $service->rewriteForUser($user, $request->all())
        );
    }

    private function run(Request $request, callable $callback): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof UserAccount) {
                return $this->error('请先登录。', 401, 'unauthenticated');
            }

            return $this->success($callback($user));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, 'module_request_failed');
        } catch (Throwable) {
            return $this->error('模块服务暂时不可用，请稍后重试。', 500, 'module_unavailable');
        }
    }

    private function success(array $data = [], string $message = 'ok'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ]);
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
