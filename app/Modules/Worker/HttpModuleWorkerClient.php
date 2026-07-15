<?php

namespace App\Modules\Worker;

use App\Contracts\Modules\ModuleWorkerClient;
use App\Models\SystemModuleRelease;
use App\Modules\ModuleIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class HttpModuleWorkerClient implements ModuleWorkerClient
{
    private const RESPONSE_TOO_LARGE = '模块 Worker 响应大小超过限制。';

    public function __construct(private readonly ModuleWorkerRequestSigner $signer) {}

    public function health(): array
    {
        $response = $this->send('GET', '/v1/health', '', 'worker-health-'.Str::uuid(), '', '');
        if (
            ! isset($response['status'], $response['protocol_version'], $response['modules'])
            || ! is_string($response['status'])
            || ! is_string($response['protocol_version'])
            || ! is_array($response['modules'])
        ) {
            throw new RuntimeException('模块 Worker 健康响应结构无效。');
        }

        return $response;
    }

    public function invoke(ModuleIdentity $identity, string $operation, array $payload, string $requestId): array
    {
        $operation = trim($operation);
        $requestId = trim($requestId);
        if ($operation === '' || strlen($operation) > 120 || $requestId === '' || strlen($requestId) > 80) {
            throw new RuntimeException('模块 Worker 调用参数无效。');
        }
        if ($identity->releaseId === null) {
            throw new RuntimeException('模块 Worker 调用缺少不可变制品身份。');
        }

        $release = SystemModuleRelease::query()->find($identity->releaseId);
        if (
            $release === null
            || (string) $release->module !== $identity->name
            || (string) $release->status !== 'active'
        ) {
            throw new RuntimeException('模块 Worker 调用的制品身份无效。');
        }
        $manifest = is_array($release->manifest_json) ? $release->manifest_json : [];
        $worker = is_array($manifest['worker'] ?? null) ? $manifest['worker'] : [];
        $operations = is_array($worker['operations'] ?? null) ? $worker['operations'] : [];
        if (! in_array($operation, $operations, true)) {
            throw new RuntimeException("模块制品未声明 Worker 操作 [{$operation}]。");
        }
        $externalDomains = is_array($manifest['external_domains'] ?? null)
            ? array_values(array_filter($manifest['external_domains'], 'is_string'))
            : [];
        $capabilities = is_array($manifest['permissions'] ?? null)
            ? array_values(array_unique(array_map(
                'trim',
                array_filter(
                    $manifest['permissions'],
                    static fn (mixed $capability): bool => is_string($capability) && trim($capability) !== ''
                )
            )))
            : [];

        $body = $this->encode([
            'module' => $identity->name,
            'release_hash' => (string) $release->artifact_hash,
            'trust_level' => (string) $release->trust_level,
            'capabilities' => $capabilities,
            'external_domains' => $externalDomains,
            'operation' => $operation,
            'payload' => $payload,
            'request_id' => $requestId,
        ]);
        $response = $this->send(
            'POST',
            '/v1/invoke',
            $body,
            $requestId,
            $identity->name,
            (string) $release->artifact_hash
        );
        if (($response['ok'] ?? null) !== true || ! is_array($response['data'] ?? null)) {
            throw new RuntimeException('模块 Worker 调用响应结构无效。');
        }

        return $response['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        string $body,
        string $requestId,
        string $module,
        string $releaseHash
    ): array {
        $url = $this->workerUrl();
        $headers = $this->signer->requestHeaders($method, $path, $requestId, $module, $releaseHash, $body);

        try {
            $request = Http::acceptJson()
                ->withoutRedirecting()
                ->withOptions($this->responseLimitOptions())
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->withHeaders($headers);
            $response = $this->sendRequest($request, $method, $url.$path, $body);
        } catch (ConnectionException) {
            throw new RuntimeException('模块 Worker 连接失败或超时。');
        } catch (Throwable $exception) {
            if ($this->isResponseTooLarge($exception)) {
                throw new RuntimeException(self::RESPONSE_TOO_LARGE);
            }

            throw new RuntimeException('模块 Worker 请求失败。');
        }

        $responseBody = $response->body();
        if (strlen($responseBody) > $this->maxResponseBytes()) {
            throw new RuntimeException(self::RESPONSE_TOO_LARGE);
        }
        if (! $this->signer->verifyResponse($path, $response->status(), $requestId, $responseBody, $response->headers())) {
            throw new RuntimeException('模块 Worker 响应签名校验失败。');
        }
        if (! $response->successful()) {
            throw new RuntimeException('模块 Worker 返回失败状态。');
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('模块 Worker 响应不是有效 JSON。');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('模块 Worker 响应结构无效。');
        }

        return $decoded;
    }

    private function sendRequest(PendingRequest $request, string $method, string $url, string $body): Response
    {
        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $url);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('模块 Worker 请求数据无法编码。');
        }
    }

    private function workerUrl(): string
    {
        $url = trim((string) config('modules.worker.url', ''));
        if ($url === '') {
            throw new RuntimeException('模块 Worker 根地址未配置或无效。');
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new RuntimeException('模块 Worker 根地址未配置或无效。');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('模块 Worker 根地址未配置或无效。');
        }
        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('模块 Worker 必须配置为不含凭据、路径、查询参数或片段的根地址。');
        }
        if (app()->environment('production') && $scheme !== 'https') {
            throw new RuntimeException('生产环境模块 Worker 必须使用 HTTPS。');
        }

        return rtrim($url, '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(60, (int) config('modules.worker.timeout_seconds', 10)));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min(30, (int) config('modules.worker.connect_timeout_seconds', 3)));
    }

    private function maxResponseBytes(): int
    {
        return max(1, min(10 * 1024 * 1024, (int) config('modules.worker.max_response_bytes', 1024 * 1024)));
    }

    /**
     * @return array<string, callable>
     */
    private function responseLimitOptions(): array
    {
        $maxBytes = $this->maxResponseBytes();

        return [
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    throw new RuntimeException(self::RESPONSE_TOO_LARGE);
                }
            },
            'progress' => static function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
                if ($downloadedBytes > $maxBytes || ($downloadTotal > 0 && $downloadTotal > $maxBytes)) {
                    throw new RuntimeException(self::RESPONSE_TOO_LARGE);
                }
            },
        ];
    }

    private function isResponseTooLarge(Throwable $exception): bool
    {
        do {
            if ($exception->getMessage() === self::RESPONSE_TOO_LARGE) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }
}
