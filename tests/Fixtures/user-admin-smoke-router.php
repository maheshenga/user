<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mode = getenv('SMOKE_FIXTURE_MODE') ?: '';
$sessionId = $_COOKIE['ADMINSMOKESESSID'] ?? bin2hex(random_bytes(16));

if (! isset($_COOKIE['ADMINSMOKESESSID'])) {
    setcookie('ADMINSMOKESESSID', $sessionId, [
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-admin-smoke-' . hash('sha256', $sessionId) . '.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];

if (! is_array($state)) {
    $state = [];
}

$state += [
    'logged_in' => false,
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
    echo '<!doctype html><html><head><meta name="csrf-token" content="fixture-admin-token"><title>'
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
    parse_str($raw, $parsed);

    return is_array($parsed) ? $parsed : [];
};

$isJsonRequest = static function (): bool {
    return str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
};

if ($method === 'GET' && $path === '/admin/login') {
    $html('Admin Login');
    return;
}

if ($method === 'POST' && $path === '/admin/login') {
    $payload = $input();

    if (trim((string) ($payload['username'] ?? '')) === '' || (string) ($payload['password'] ?? '') === '') {
        $json([
            'code' => 0,
            'msg' => 'username and password are required',
            '__token__' => 'fixture-admin-token',
        ]);
        return;
    }

    $state['logged_in'] = true;
    $saveState($state);
    $json([
        'code' => 1,
        'msg' => 'logged in',
        'url' => '/admin',
        '__token__' => 'fixture-admin-token-refreshed',
    ]);
    return;
}

if (! $state['logged_in']) {
    http_response_code(401);
    echo 'Unauthorized';
    return;
}

if ($method === 'GET' && $path === '/admin/ajax/initAdmin') {
    $menuInfo = [];

    if ($mode !== 'missing-menu') {
        $menuInfo[] = [
            'title' => 'User Operations',
            'href' => '',
            'child' => [
                ['title' => 'Overview', 'href' => '/admin/user/dashboard/index'],
                ['title' => 'User Accounts', 'href' => '/admin/user/account/index'],
            ],
        ];
    }

    $json([
        'logoInfo' => ['title' => 'EasyAdmin8'],
        'homeInfo' => ['title' => 'Home', 'href' => '/admin'],
        'menuInfo' => $menuInfo,
    ]);
    return;
}

if ($method === 'GET' && $path === '/admin/user/dashboard/index' && $isJsonRequest()) {
    $metrics = [
        'total_users' => 1,
        'today_registrations' => 1,
        'active_vip_users' => 0,
        'pending_withdrawals' => 0,
        'pending_payouts' => 0,
        'pending_notifications' => 0,
        'retryable_notifications' => 0,
        'risk_events' => 0,
        'today_commission_amount' => '0.00',
    ];

    if ($mode === 'missing-dashboard-metric') {
        unset($metrics['pending_payouts']);
    }

    $json([
        'code' => 1,
        'msg' => 'User operations metrics.',
        'data' => $metrics,
        '__token__' => 'fixture-admin-token-refreshed',
    ]);
    return;
}

if ($method === 'GET' && in_array($path, [
    '/admin/user/dashboard/index',
    '/admin/user/account/index',
    '/admin/user/withdrawal/index',
    '/admin/user/risk-event/index',
    '/admin/user/notification-outbox/index',
], true)) {
    if ($mode === 'page-error' && $path === '/admin/user/account/index') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><body><div class="system-message error"><h1>No permission</h1></div></body></html>';
        return;
    }

    $html($path);
    return;
}

http_response_code(404);
echo 'Not Found';
