<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mode = getenv('SMOKE_FIXTURE_MODE') ?: '';
$sessionId = $_COOKIE['SMOKESESSID'] ?? bin2hex(random_bytes(16));

if (! isset($_COOKIE['SMOKESESSID'])) {
    setcookie('SMOKESESSID', $sessionId, [
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-portal-smoke-' . hash('sha256', $sessionId) . '.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];

if (! is_array($state)) {
    $state = [];
}

$state += [
    'logged_in' => false,
    'email' => null,
];

$saveState = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
};

$html = static function (string $title): void {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="csrf-token" content="fixture-token"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title></head><body><main>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</main></body></html>';
};

$input = static function (): array {
    if ($_POST !== []) {
        return $_POST;
    }

    $raw = (string) file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    parse_str($raw, $parsed);

    return is_array($parsed) ? $parsed : [];
};

if ($method === 'GET' && $path === '/u') {
    http_response_code(302);
    header('Location: /u/dashboard');
    return;
}

if ($method === 'GET' && $path === '/static/user/js/portal.js') {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo <<<'JS'
(function () {
    function request(endpoint) {
        return endpoint;
    }
    function loadBox(name, endpoint) {
        return [name, endpoint];
    }
    const endpoints = {
        activation: '/user/activation-code/redeem',
        withdrawalRequest: '/user/withdrawal/request',
        vip: '/user/vip',
        withdrawals: '/user/withdrawal'
    };
    document.querySelector('[data-dashboard-form="activation"]');
    document.querySelector('[data-dashboard-form="withdrawal"]');
    request(endpoints.activation);
    request(endpoints.withdrawalRequest);
    loadBox('vip', endpoints.vip);
    loadBox('withdrawals', endpoints.withdrawals);
}());
JS;
    return;
}

if ($method === 'GET' && $path === '/u/dashboard') {
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<'HTML'
<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="fixture-token">
    <title>Dashboard</title>
</head>
<body>
<main>
    <div data-dashboard-endpoints
         data-activation="/user/activation-code/redeem"
         data-withdrawal-request="/user/withdrawal/request"
         data-vip="/user/vip"
         data-withdrawals="/user/withdrawal"></div>
    <form data-dashboard-form="activation"></form>
    <form data-dashboard-form="withdrawal"></form>
</main>
<script src="/static/user/js/portal.js"></script>
</body>
</html>
HTML;
    return;
}

if ($method === 'GET' && in_array($path, [
    '/u/login',
    '/u/register',
    '/u/forgot-password',
    '/u/reset-password',
    '/u/dashboard',
], true)) {
    $html($path);
    return;
}

if ($method === 'POST' && $path === '/user/register') {
    $payload = $input();
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        $json([
            'code' => 0,
            'msg' => 'Email and password are required.',
        ]);
        return;
    }

    $state['email'] = $email;
    $saveState($state);
    $json([
        'code' => 1,
        'msg' => 'registered',
        '__token__' => 'fixture-token-refreshed',
        'data' => [
            '__token__' => 'fixture-token-refreshed',
            'user' => [
                'email' => $state['email'],
            ],
        ],
    ]);
    return;
}

if ($method === 'POST' && $path === '/user/login') {
    $payload = $input();
    $account = trim((string) ($payload['account'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($account === '' || $password === '') {
        $json([
            'code' => 0,
            'msg' => '请填写账号和密码。',
        ]);
        return;
    }

    $state['logged_in'] = true;
    $state['email'] = $account;
    $saveState($state);
    $json([
        'code' => 1,
        'msg' => 'logged in',
        'data' => [
            'user' => [
                'email' => $state['email'],
            ],
        ],
    ]);
    return;
}

if ($method === 'POST' && $path === '/user/logout') {
    $state['logged_in'] = false;
    $saveState($state);
    $json([
        'code' => 1,
        'msg' => 'logged out',
    ]);
    return;
}

if ($method === 'GET' && $path === '/user/session') {
    if (! $state['logged_in']) {
        $json([
            'code' => 0,
            'msg' => 'not logged in',
        ]);
        return;
    }

    if ($mode === 'broken-session') {
        $json([
            'code' => 1,
            'data' => [
                'user' => [
                    'id' => 1,
                ],
            ],
        ]);
        return;
    }

    $json([
        'code' => 1,
        'data' => [
            'user' => [
                'id' => 1,
                'email' => $state['email'],
            ],
        ],
    ]);
    return;
}

if ($method === 'GET' && in_array($path, [
    '/user/vip',
    '/user/balance',
    '/user/balance/ledger',
    '/user/invite',
    '/user/invite/records',
    '/user/withdrawal',
], true)) {
    $json([
        'code' => $state['logged_in'] ? 1 : 0,
        'data' => [],
    ]);
    return;
}

if ($method === 'POST' && $path === '/user/activation-code/redeem') {
    $payload = $input();

    if (! $state['logged_in']) {
        $json([
            'code' => 0,
            'msg' => 'not logged in',
        ]);
        return;
    }

    if (trim((string) ($payload['code'] ?? '')) === '') {
        $json([
            'code' => 0,
            'msg' => 'Activation code is required.',
        ]);
        return;
    }

    $json([
        'code' => 1,
        'msg' => 'activation redeemed',
        'data' => [
            'vip_level' => 1,
        ],
    ]);
    return;
}

if ($method === 'POST' && $path === '/user/withdrawal/request') {
    $payload = $input();

    if (! $state['logged_in']) {
        $json([
            'code' => 0,
            'msg' => 'not logged in',
        ]);
        return;
    }

    if (! is_numeric($payload['amount'] ?? null) || (float) $payload['amount'] <= 0) {
        $json([
            'code' => 0,
            'msg' => 'Withdrawal amount must be positive.',
        ]);
        return;
    }

    $json([
        'code' => 1,
        'msg' => 'withdrawal requested',
        'data' => [
            'status' => 'pending_review',
        ],
    ]);
    return;
}

http_response_code(404);
echo 'Not Found';
