<?php

declare(strict_types=1);

final class SmokeFailure extends RuntimeException
{
}

/**
 * @return array{base_url:string,email:string,password:string,timeout:float}
 */
function parseOptions(array $argv): array
{
    $options = getopt('', [
        'base-url:',
        'email::',
        'password::',
        'timeout::',
    ]);

    if ($options === false || ! isset($options['base-url']) || ! is_string($options['base-url'])) {
        throw new SmokeFailure('Missing required option: --base-url');
    }

    $baseUrl = rtrim(trim($options['base-url']), '/');

    if ($baseUrl === '') {
        throw new SmokeFailure('Missing required option: --base-url');
    }

    $email = isset($options['email']) && is_string($options['email']) && trim($options['email']) !== ''
        ? trim($options['email'])
        : 'smoke+' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '@example.test';

    $password = isset($options['password']) && is_string($options['password']) && $options['password'] !== ''
        ? $options['password']
        : 'secret123';

    $timeout = 10.0;

    if (isset($options['timeout'])) {
        if (! is_string($options['timeout']) || ! is_numeric($options['timeout']) || (float) $options['timeout'] <= 0) {
            throw new SmokeFailure('Invalid --timeout value.');
        }

        $timeout = (float) $options['timeout'];
    }

    return [
        'base_url' => $baseUrl,
        'email' => $email,
        'password' => $password,
        'timeout' => $timeout,
    ];
}

final class SmokeHttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private ?string $csrfToken = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeout,
    ) {
    }

    public function setCsrfToken(string $csrfToken): void
    {
        $this->csrfToken = $csrfToken;
    }

    /**
     * @param array<string, string> $payload
     * @return array{status:int,body:string,json:?array<string, mixed>}
     */
    public function request(string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);
        $headers = [
            'Accept: text/html, application/json',
            'Connection: close',
        ];

        if ($this->cookies !== []) {
            $cookiePairs = [];

            foreach ($this->cookies as $name => $value) {
                $cookiePairs[] = $name . '=' . $value;
            }

            $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
        }

        $content = null;

        if ($method !== 'GET') {
            $content = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($content);

            if ($this->csrfToken !== null) {
                $headers[] = 'X-CSRF-TOKEN: ' . $this->csrfToken;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($body === false) {
            throw new SmokeFailure("{$method} {$path} request failed.");
        }

        $status = $this->statusCode($responseHeaders);

        if ($status === 0) {
            throw new SmokeFailure("{$method} {$path} missing HTTP status.");
        }

        $this->captureCookies($responseHeaders);

        $json = json_decode($body, true);
        $json = is_array($json) ? $json : null;
        $newToken = $json === null ? null : findToken($json);

        if ($newToken !== null) {
            $this->csrfToken = $newToken;
        }

        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusCode(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /**
     * @param array<int, string> $headers
     */
    private function captureCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }

            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $pair = explode(';', $cookie, 2)[0];
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '') {
                continue;
            }

            $this->cookies[trim($parts[0])] = trim($parts[1]);
        }
    }
}

/**
 * @param array<string, mixed> $payload
 */
function findToken(array $payload): ?string
{
    foreach ($payload as $key => $value) {
        if ($key === '__token__' && is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $token = findToken($value);

            if ($token !== null) {
                return $token;
            }
        }
    }

    return null;
}

function csrfFromHtml(string $html): ?string
{
    if (preg_match('/<meta\b(?=[^>]*\bname=["\']csrf-token["\'])(?=[^>]*\bcontent=["\']([^"\']+)["\'])[^>]*>/i', $html, $matches) !== 1) {
        return null;
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @param array{status:int,body:string,json:?array<string, mixed>} $response
 * @param list<int> $expected
 */
function expectStatus(array $response, array $expected, string $label): void
{
    if (! in_array($response['status'], $expected, true)) {
        throw new SmokeFailure("{$label} returned HTTP {$response['status']}; expected " . implode(' or ', $expected) . '.');
    }
}

/**
 * @param array{status:int,body:string,json:?array<string, mixed>} $response
 */
function expectJsonCode(array $response, int $code, string $label): void
{
    if ($response['json'] === null) {
        throw new SmokeFailure("{$label} did not return JSON.");
    }

    if (($response['json']['code'] ?? null) !== $code) {
        throw new SmokeFailure("{$label} returned JSON code " . var_export($response['json']['code'] ?? null, true) . "; expected {$code}.");
    }
}

function pass(string $message): void
{
    fwrite(STDOUT, "PASS {$message}\n");
}

function runSmoke(): void
{
    $options = parseOptions($_SERVER['argv'] ?? []);
    $client = new SmokeHttpClient($options['base_url'], $options['timeout']);

    $response = $client->request('GET', '/u/register');
    expectStatus($response, [200], 'GET /u/register');

    $csrfToken = csrfFromHtml($response['body']);

    if ($csrfToken === null) {
        throw new SmokeFailure('GET /u/register missing CSRF token.');
    }

    $client->setCsrfToken($csrfToken);
    pass('GET /u/register loaded CSRF token');

    foreach ([
        '/u' => [200, 302],
        '/u/login' => [200],
        '/u/register' => [200],
        '/u/forgot-password' => [200],
        '/u/reset-password' => [200],
        '/u/dashboard' => [200],
    ] as $path => $expectedStatuses) {
        $response = $client->request('GET', $path);
        expectStatus($response, $expectedStatuses, "GET {$path}");
        pass("GET {$path}");
    }

    $response = $client->request('GET', '/user/session');
    expectJsonCode($response, 0, 'GET /user/session before login');
    pass('GET /user/session before login');

    $response = $client->request('POST', '/user/register', [
        'email' => $options['email'],
        'password' => $options['password'],
    ]);
    expectJsonCode($response, 1, 'POST /user/register');
    pass('POST /user/register');

    $response = $client->request('POST', '/user/login', [
        'account' => $options['email'],
        'password' => $options['password'],
    ]);
    expectJsonCode($response, 1, 'POST /user/login');
    pass('POST /user/login');

    $response = $client->request('GET', '/user/session');
    expectJsonCode($response, 1, 'GET /user/session logged in');

    if (($response['json']['data']['user']['email'] ?? null) !== $options['email']) {
        throw new SmokeFailure('Session response missing matching user email');
    }

    pass('GET /user/session logged in');

    foreach ([
        '/user/vip',
        '/user/balance',
        '/user/balance/ledger',
        '/user/invite',
        '/user/invite/records',
        '/user/withdrawal',
    ] as $path) {
        $response = $client->request('GET', $path);
        expectStatus($response, [200], "GET {$path}");
        expectJsonCode($response, 1, "GET {$path}");
        pass("GET {$path}");
    }

    $response = $client->request('POST', '/user/logout');
    expectJsonCode($response, 1, 'POST /user/logout');
    pass('POST /user/logout');

    $response = $client->request('GET', '/user/session');
    expectJsonCode($response, 0, 'GET /user/session after logout');
    pass('GET /user/session after logout');

    fwrite(STDOUT, "OK user portal smoke passed\n");
}

try {
    runSmoke();
    exit(0);
} catch (SmokeFailure $exception) {
    fwrite(STDERR, "FAIL user portal smoke failed\n{$exception->getMessage()}\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL user portal smoke failed\n{$exception->getMessage()}\n");
    exit(1);
}
