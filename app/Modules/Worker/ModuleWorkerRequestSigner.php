<?php

namespace App\Modules\Worker;

use RuntimeException;

final class ModuleWorkerRequestSigner
{
    private const BODY_HASH = 'X-Module-Worker-Body-SHA256';

    private const KEY_ID = 'X-Module-Worker-Key-Id';

    private const MODULE = 'X-Module-Name';

    private const NONCE = 'X-Module-Worker-Nonce';

    private const PROTOCOL = 'X-Module-Worker-Protocol';

    private const RELEASE_HASH = 'X-Module-Release-Hash';

    private const REQUEST_ID = 'X-Module-Request-Id';

    private const SIGNATURE = 'X-Module-Worker-Signature';

    private const TIMESTAMP = 'X-Module-Worker-Timestamp';

    /**
     * @return array<string, string>
     */
    public function requestHeaders(
        string $method,
        string $path,
        string $requestId,
        string $module,
        string $releaseHash,
        string $body
    ): array {
        [$keyId, $key] = $this->activeKey();
        $headers = $this->unsignedHeaders($requestId, $module, $releaseHash, $body, $keyId);
        $headers[self::SIGNATURE] = hash_hmac(
            'sha256',
            $this->requestCanonical($method, $path, $headers),
            $key
        );

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(string $path, int $status, string $requestId, string $body): array
    {
        [$keyId, $key] = $this->activeKey();
        $headers = $this->unsignedHeaders($requestId, '', '', $body, $keyId);
        $headers[self::SIGNATURE] = hash_hmac(
            'sha256',
            $this->responseCanonical($path, $status, $headers),
            $key
        );

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyRequest(string $method, string $path, array $headers, string $body): bool
    {
        $normalized = $this->normalizeHeaders($headers);

        return $this->verify(
            $normalized,
            $body,
            fn (): string => $this->requestCanonical($method, $path, $normalized)
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyResponse(
        string $path,
        int $status,
        string $requestId,
        string $body,
        array $headers
    ): bool {
        $normalized = $this->normalizeHeaders($headers);
        if (($normalized[strtolower(self::REQUEST_ID)] ?? '') !== $requestId) {
            return false;
        }

        return $this->verify(
            $normalized,
            $body,
            fn (): string => $this->responseCanonical($path, $status, $normalized)
        );
    }

    /**
     * @return array<string, string>
     */
    private function unsignedHeaders(
        string $requestId,
        string $module,
        string $releaseHash,
        string $body,
        string $keyId
    ): array {
        return [
            self::PROTOCOL => $this->protocolVersion(),
            self::KEY_ID => $keyId,
            self::TIMESTAMP => (string) now()->timestamp,
            self::NONCE => bin2hex(random_bytes(16)),
            self::REQUEST_ID => $requestId,
            self::MODULE => $module,
            self::RELEASE_HASH => $releaseHash,
            self::BODY_HASH => hash('sha256', $body),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verify(array $headers, string $body, callable $canonical): bool
    {
        $keyId = $headers[strtolower(self::KEY_ID)] ?? '';
        $signature = $headers[strtolower(self::SIGNATURE)] ?? '';
        $timestamp = $headers[strtolower(self::TIMESTAMP)] ?? '';
        $nonce = $headers[strtolower(self::NONCE)] ?? '';
        if (
            $keyId === ''
            || $signature === ''
            || ! ctype_digit($timestamp)
            || $nonce === ''
            || strlen($nonce) > 128
            || abs(now()->timestamp - (int) $timestamp) > $this->clockSkewSeconds()
            || ! hash_equals(hash('sha256', $body), $headers[strtolower(self::BODY_HASH)] ?? '')
            || ($headers[strtolower(self::PROTOCOL)] ?? '') !== $this->protocolVersion()
        ) {
            return false;
        }

        $key = $this->keys()[$keyId] ?? '';
        if (strlen($key) < 32) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $canonical(), $key), $signature);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function requestCanonical(string $method, string $path, array $headers): string
    {
        return $this->canonical('request', strtoupper($method), $path, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function responseCanonical(string $path, int $status, array $headers): string
    {
        return $this->canonical('response', (string) $status, $path, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function canonical(string $direction, string $verb, string $path, array $headers): string
    {
        $normalized = $this->normalizeHeaders($headers);

        return implode("\n", [
            $direction,
            $verb,
            $path,
            $normalized[strtolower(self::PROTOCOL)] ?? '',
            $normalized[strtolower(self::KEY_ID)] ?? '',
            $normalized[strtolower(self::TIMESTAMP)] ?? '',
            $normalized[strtolower(self::NONCE)] ?? '',
            $normalized[strtolower(self::REQUEST_ID)] ?? '',
            $normalized[strtolower(self::MODULE)] ?? '',
            $normalized[strtolower(self::RELEASE_HASH)] ?? '',
            $normalized[strtolower(self::BODY_HASH)] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            if (is_scalar($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array{string, string}
     */
    private function activeKey(): array
    {
        $keyId = trim((string) config('modules.worker.active_key_id', ''));
        $key = $this->keys()[$keyId] ?? '';
        if ($keyId === '' || strlen($key) < 32) {
            throw new RuntimeException('模块 Worker 活动签名密钥未配置或长度不足。');
        }

        return [$keyId, $key];
    }

    /**
     * @return array<string, string>
     */
    private function keys(): array
    {
        $keys = [];
        foreach ((array) config('modules.worker.keys', []) as $keyId => $key) {
            if (is_string($keyId) && trim($keyId) !== '' && is_string($key)) {
                $keys[trim($keyId)] = trim($key);
            }
        }

        return $keys;
    }

    private function protocolVersion(): string
    {
        return trim((string) config('modules.worker.protocol_version', '1.0'));
    }

    private function clockSkewSeconds(): int
    {
        return max(1, min(3600, (int) config('modules.worker.clock_skew_seconds', 300)));
    }
}
