<?php

declare(strict_types=1);

final class AdminSmokeFailure extends RuntimeException {}

/**
 * @return array{base_url:string,admin_prefix:string,username:string,password:string,timeout:float}
 */
function parseOptions(array $argv): array
{
    $options = getopt('', [
        'base-url:',
        'admin-prefix:',
        'username:',
        'password:',
        'timeout:',
    ]);

    if ($options === false || ! isset($options['base-url']) || ! is_string($options['base-url'])) {
        throw new AdminSmokeFailure('Missing required option: --base-url');
    }

    $baseUrl = rtrim(trim($options['base-url']), '/');

    if ($baseUrl === '') {
        throw new AdminSmokeFailure('Missing required option: --base-url');
    }

    $adminPrefix = isset($options['admin-prefix']) && is_string($options['admin-prefix']) && trim($options['admin-prefix']) !== ''
        ? trim($options['admin-prefix'], " \t\n\r\0\x0B/")
        : 'admin';

    $username = isset($options['username']) && is_string($options['username']) && trim($options['username']) !== ''
        ? trim($options['username'])
        : 'admin';

    $password = isset($options['password']) && is_string($options['password']) && $options['password'] !== ''
        ? $options['password']
        : '123456';

    $timeout = 10.0;

    if (isset($options['timeout'])) {
        if (! is_string($options['timeout']) || ! is_numeric($options['timeout']) || (float) $options['timeout'] <= 0) {
            throw new AdminSmokeFailure('Invalid --timeout value.');
        }

        $timeout = (float) $options['timeout'];
    }

    return [
        'base_url' => $baseUrl,
        'admin_prefix' => $adminPrefix,
        'username' => $username,
        'password' => $password,
        'timeout' => $timeout,
    ];
}

final class AdminSmokeHttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private ?string $csrfToken = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeout,
    ) {}

    public function setCsrfToken(string $csrfToken): void
    {
        $this->csrfToken = $csrfToken;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{status:int,body:string,json:?array<string, mixed>}
     */
    public function request(string $method, string $path, array $payload = [], bool $ajax = false, bool $jsonAccept = false): array
    {
        $method = strtoupper($method);
        $headers = [
            'Accept: '.($jsonAccept ? 'application/json' : 'text/html, application/json'),
            'Connection: close',
        ];

        if ($ajax) {
            $headers[] = 'X-Requested-With: XMLHttpRequest';
        }

        if ($this->cookies !== []) {
            $cookiePairs = [];

            foreach ($this->cookies as $name => $value) {
                $cookiePairs[] = $name.'='.$value;
            }

            $headers[] = 'Cookie: '.implode('; ', $cookiePairs);
        }

        $content = null;

        if ($method !== 'GET') {
            $content = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: '.strlen($content);

            if ($this->csrfToken !== null) {
                $headers[] = 'X-CSRF-TOKEN: '.$this->csrfToken;
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

        $body = @file_get_contents($this->baseUrl.$path, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($body === false) {
            throw new AdminSmokeFailure("{$method} {$path} request failed.");
        }

        $status = $this->statusCode($responseHeaders);

        if ($status === 0) {
            throw new AdminSmokeFailure("{$method} {$path} missing HTTP status.");
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
     * @param  array<int, string>  $headers
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
     * @param  array<int, string>  $headers
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
 * @param  array<string, mixed>  $payload
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

function adminPath(string $prefix, string $path = ''): string
{
    $path = trim($path, '/');

    return '/'.trim($prefix, '/').($path === '' ? '' : '/'.$path);
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 * @param  list<int>  $expected
 */
function expectStatus(array $response, array $expected, string $label): void
{
    if (! in_array($response['status'], $expected, true)) {
        throw new AdminSmokeFailure("{$label} returned HTTP {$response['status']}; expected ".implode(' or ', $expected).'.');
    }
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectJsonCode(array $response, int $code, string $label): void
{
    if ($response['json'] === null) {
        throw new AdminSmokeFailure("{$label} did not return JSON.");
    }

    if (($response['json']['code'] ?? null) !== $code) {
        throw new AdminSmokeFailure("{$label} returned JSON code ".var_export($response['json']['code'] ?? null, true)."; expected {$code}.");
    }
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectJsonMessageContains(array $response, string $needle, string $label): void
{
    if ($response['json'] === null) {
        throw new AdminSmokeFailure("{$label} did not return JSON.");
    }

    $message = (string) ($response['json']['msg'] ?? '');

    if (! str_contains($message, $needle)) {
        throw new AdminSmokeFailure("{$label} returned message {$message}; expected to contain {$needle}.");
    }
}

function expectBodyContains(string $body, string $needle, string $label): void
{
    if (! str_contains($body, $needle)) {
        throw new AdminSmokeFailure("{$label} missing expected content: {$needle}");
    }
}

/**
 * @return list<string>
 */
function expectedUserOpsPaths(): array
{
    return [
        'user/dashboard/index',
        'user/account/index',
        'user/invite/index',
        'user/invite/relations',
        'user/vip-plan/index',
        'user/activation-code/index',
        'user/activation-code/redemptions',
        'user/balance/index',
        'user/commission/index',
        'user/withdrawal/index',
        'user/risk-event/index',
        'user/security-log/index',
        'user/notification-outbox/index',
        'user/settings/index',
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function expectMenu(array $payload, string $adminPrefix): void
{
    $userOpsMenu = findMenuByTitles($payload['menuInfo'] ?? [], ['用户运营', 'User Operations']);

    if ($userOpsMenu === null) {
        throw new AdminSmokeFailure('Menu response missing 用户运营.');
    }

    foreach (expectedUserOpsPaths() as $path) {
        if (! menuContainsHref($userOpsMenu, $path, $adminPrefix)) {
            throw new AdminSmokeFailure("Menu response missing {$path} under 用户运营.");
        }
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function expectModuleCenterMenu(array $payload, string $adminPrefix): void
{
    $systemMenu = findMenuByTitles($payload['menuInfo'] ?? [], ['系统管理', 'System']);

    if ($systemMenu === null) {
        throw new AdminSmokeFailure('Menu response missing 系统管理.');
    }

    if (! menuContainsHref($systemMenu, 'system/module/index', $adminPrefix)) {
        throw new AdminSmokeFailure('Menu response missing system/module/index under 系统管理.');
    }
}

/**
 * @param  mixed  $node
 * @param  array<int, string>  $titles
 * @return array<string, mixed>|null
 */
function findMenuByTitles($node, array $titles): ?array
{
    foreach ($titles as $title) {
        $match = findMenuByTitle($node, $title);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

/**
 * @param  mixed  $node
 * @return array<string, mixed>|null
 */
function findMenuByTitle($node, string $title): ?array
{
    if (! is_array($node)) {
        return null;
    }

    if (($node['title'] ?? null) === $title) {
        return $node;
    }

    foreach ($node as $child) {
        $match = findMenuByTitle($child, $title);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

/**
 * @param  mixed  $node
 */
function menuContainsHref($node, string $expectedPath, string $adminPrefix): bool
{
    if (! is_array($node)) {
        return false;
    }

    if (isset($node['href']) && is_string($node['href']) && normalizeMenuPath($node['href'], $adminPrefix) === $expectedPath) {
        return true;
    }

    foreach (($node['child'] ?? []) as $child) {
        if (menuContainsHref($child, $expectedPath, $adminPrefix)) {
            return true;
        }
    }

    return false;
}

function normalizeMenuPath(string $href, string $adminPrefix): string
{
    $path = (string) (parse_url($href, PHP_URL_PATH) ?: $href);
    $path = trim($path, '/');
    $prefix = trim($adminPrefix, '/');

    if ($prefix !== '' && str_starts_with($path.'/', $prefix.'/')) {
        $path = substr($path, strlen($prefix) + 1);
    }

    return $path;
}

/**
 * @param  array<string, mixed>  $payload
 */
function expectDashboardMetrics(array $payload): void
{
    $data = $payload['data'] ?? null;

    if (! is_array($data)) {
        throw new AdminSmokeFailure('Dashboard response missing data object.');
    }

    foreach ([
        'total_users',
        'today_registrations',
        'active_vip_users',
        'pending_withdrawals',
        'pending_payouts',
        'pending_notifications',
        'retryable_notifications',
        'risk_events',
        'today_commission_amount',
    ] as $key) {
        if (! array_key_exists($key, $data)) {
            throw new AdminSmokeFailure("Dashboard metrics missing key: {$key}");
        }
    }
}

function expectAdminPageBody(array $response, string $label): void
{
    $body = strtolower($response['body']);

    if (str_contains($body, 'system-message error')) {
        throw new AdminSmokeFailure("{$label} looks like an EasyAdmin error page.");
    }

    if (
        str_contains($body, 'id="loginform"')
        || str_contains($body, '/static/admin/css/login.css')
        || (str_contains($body, 'name="username"') && str_contains($body, 'name="password"'))
        || str_contains($body, '<title>admin login</title>')
    ) {
        throw new AdminSmokeFailure("{$label} looks like a login page.");
    }
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectAccountStatusPage(array $response, string $label): void
{
    expectAdminPageBody($response, $label);
    expectBodyContains($response['body'], '账号状态管理', $label);
    expectBodyContains($response['body'], 'data-status-endpoint="/admin/user/account/modify"', $label);
    expectBodyContains($response['body'], 'data-auth-modify=', $label);
    expectBodyContains($response['body'], 'id="userStatusTpl"', $label);
    expectBodyContains($response['body'], '待审核', $label);
    expectBodyContains($response['body'], '正常', $label);
    expectBodyContains($response['body'], '已禁用', $label);
    expectBodyContains($response['body'], '已冻结', $label);
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectAccountStatusScript(array $response, string $label): void
{
    expectStatus($response, [200], $label);
    expectBodyContains($response['body'], "modify_url: 'user/account/modify'", $label);
    expectBodyContains($response['body'], "templet: '#userStatusTpl'", $label);
    expectBodyContains($response['body'], 'data-status-endpoint', $label);
    expectBodyContains($response['body'], 'data-auth-modify', $label);
    expectBodyContains($response['body'], 'data-account-status', $label);
    expectBodyContains($response['body'], "field: 'status'", $label);
    expectBodyContains($response['body'], 'value: status', $label);
    expectBodyContains($response['body'], 'ea.table.reload(init.table_render_id)', $label);
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectModuleCenterPage(array $response, string $label): void
{
    expectAdminPageBody($response, $label);
    expectBodyContains($response['body'], '模块中心', $label);
    expectBodyContains($response['body'], 'id="currentTable"', $label);
    expectBodyContains($response['body'], 'lay-filter="currentTable"', $label);
}

/**
 * @param  array{status:int,body:string,json:?array<string, mixed>}  $response
 */
function expectModuleCenterScript(array $response, string $label): void
{
    expectStatus($response, [200], $label);

    foreach ([
        "index_url: 'system/module/index'",
        "discover_url: 'system/module/discover'",
        "upload_url: 'system/module/upload'",
        "install_url: 'system/module/install'",
        "approve_url: 'system/module/approve'",
        "reject_url: 'system/module/reject'",
        "enable_url: 'system/module/enable'",
        "disable_url: 'system/module/disable'",
        "uninstall_url: 'system/module/uninstall'",
        "upgradeLocal_url: 'system/module/upgradeLocal'",
        "rollback_url: 'system/module/rollback'",
        'data-module-action',
        'data-module-reject',
        'data-review-detail',
    ] as $needle) {
        expectBodyContains($response['body'], $needle, $label);
    }
}

function expectAccountStatusEndpointGuards(AdminSmokeHttpClient $client, string $prefix): void
{
    $label = 'POST /'.$prefix.'/user/account/modify status endpoint guards';

    $response = $client->request('POST', adminPath($prefix, 'user/account/modify'), [
        'id' => '1',
        'field' => 'nickname',
        'value' => 'Smoke Probe',
    ], ajax: true, jsonAccept: true);
    expectStatus($response, [200], $label.' non-status field');
    expectJsonCode($response, 0, $label.' non-status field');
    expectJsonMessageContains($response, '用户账号管理仅允许修改账号状态', $label.' non-status field');

    $response = $client->request('POST', adminPath($prefix, 'user/account/modify'), [
        'id' => '1',
        'field' => 'status',
        'value' => 'archived',
    ], ajax: true, jsonAccept: true);
    expectStatus($response, [200], $label.' invalid status');
    expectJsonCode($response, 0, $label.' invalid status');
    expectJsonMessageContains($response, '账号状态值无效', $label.' invalid status');
}

function pass(string $message): void
{
    fwrite(STDOUT, "PASS {$message}\n");
}

function runAdminSmoke(): void
{
    $options = parseOptions($_SERVER['argv'] ?? []);
    $client = new AdminSmokeHttpClient($options['base_url'], $options['timeout']);
    $prefix = $options['admin_prefix'];

    $response = $client->request('GET', adminPath($prefix, 'login'));
    expectStatus($response, [200], 'GET /'.$prefix.'/login');

    $csrfToken = csrfFromHtml($response['body']);

    if ($csrfToken === null) {
        throw new AdminSmokeFailure('GET /'.$prefix.'/login missing CSRF token.');
    }

    $client->setCsrfToken($csrfToken);
    pass('GET /'.$prefix.'/login loaded CSRF token');

    $response = $client->request('POST', adminPath($prefix, 'login'), [
        'username' => $options['username'],
        'password' => $options['password'],
        'keep_login' => '1',
    ], ajax: true, jsonAccept: true);
    expectStatus($response, [200], 'POST /'.$prefix.'/login');
    expectJsonCode($response, 1, 'POST /'.$prefix.'/login');
    pass('POST /'.$prefix.'/login');

    $response = $client->request('GET', adminPath($prefix, 'ajax/initAdmin'), ajax: true, jsonAccept: true);
    expectStatus($response, [200], 'GET /'.$prefix.'/ajax/initAdmin');

    if ($response['json'] === null) {
        throw new AdminSmokeFailure('GET /'.$prefix.'/ajax/initAdmin did not return JSON.');
    }

    expectMenu($response['json'], $prefix);
    pass('GET /'.$prefix.'/ajax/initAdmin menu contains 用户运营');
    expectModuleCenterMenu($response['json'], $prefix);
    pass('GET /'.$prefix.'/ajax/initAdmin menu contains 模块管理');

    $response = $client->request('GET', adminPath($prefix, 'user/dashboard/index'), ajax: true, jsonAccept: true);
    expectStatus($response, [200], 'GET /'.$prefix.'/user/dashboard/index JSON');
    expectJsonCode($response, 1, 'GET /'.$prefix.'/user/dashboard/index JSON');
    expectDashboardMetrics($response['json'] ?? []);
    pass('GET /'.$prefix.'/user/dashboard/index JSON metrics');

    foreach (expectedUserOpsPaths() as $path) {
        $response = $client->request('GET', adminPath($prefix, $path));
        expectStatus($response, [200], 'GET /'.$prefix.'/'.$path);
        expectAdminPageBody($response, 'GET /'.$prefix.'/'.$path);
        pass('GET /'.$prefix.'/'.$path);
    }

    $response = $client->request('GET', adminPath($prefix, 'user/account/index'));
    expectAccountStatusPage($response, 'GET /'.$prefix.'/user/account/index account status UI');
    pass('GET /'.$prefix.'/user/account/index account status UI');

    $response = $client->request('GET', '/static/admin/js/user/account.js');
    expectAccountStatusScript($response, 'GET /static/admin/js/user/account.js');
    pass('GET /static/admin/js/user/account.js status actions');

    expectAccountStatusEndpointGuards($client, $prefix);
    pass('POST /'.$prefix.'/user/account/modify status endpoint guards');

    $response = $client->request('GET', adminPath($prefix, 'system/module/index'));
    expectModuleCenterPage($response, 'GET /'.$prefix.'/system/module/index module center page');
    pass('GET /'.$prefix.'/system/module/index module center page');

    $response = $client->request('GET', '/static/admin/js/system/module.js');
    expectModuleCenterScript($response, 'GET /static/admin/js/system/module.js');
    pass('GET /static/admin/js/system/module.js module actions');

    fwrite(STDOUT, "OK user admin smoke passed\n");
}

try {
    runAdminSmoke();
    exit(0);
} catch (AdminSmokeFailure $exception) {
    fwrite(STDERR, "FAIL user admin smoke failed\n{$exception->getMessage()}\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL user admin smoke failed\n{$exception->getMessage()}\n");
    exit(1);
}
