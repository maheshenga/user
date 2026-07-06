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

$userOpsChildren = static function (string $mode): array {
    $paths = [
        'user/dashboard/index' => '运营概览',
        'user/account/index' => '用户账号',
        'user/invite/index' => '邀请码',
        'user/invite/relations' => '邀请关系',
        'user/vip-plan/index' => 'VIP 套餐',
        'user/activation-code/index' => '激活码',
        'user/activation-code/redemptions' => '激活记录',
        'user/balance/index' => '余额流水',
        'user/commission/index' => '分销佣金',
        'user/withdrawal/index' => '提现审核',
        'user/risk-event/index' => '风控事件',
        'user/security-log/index' => '安全日志',
        'user/notification-outbox/index' => '通知队列',
        'user/settings/index' => '设置',
    ];

    if ($mode === 'missing-dashboard-link' || $mode === 'dashboard-link-outside-user-ops') {
        unset($paths['user/dashboard/index']);
    }

    if ($mode === 'missing-settings-link') {
        unset($paths['user/settings/index']);
    }

    return array_map(
        static fn (string $path, string $title): array => ['title' => $title, 'href' => '/admin/' . $path],
        array_keys($paths),
        array_values($paths)
    );
};

if ($method === 'GET' && $path === '/admin/login') {
    $html('Admin Login');
    return;
}

if ($method === 'POST' && $path === '/admin/login') {
    $payload = $input();

    if (($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') !== 'fixture-admin-token') {
        $json([
            'code' => 0,
            'msg' => 'invalid csrf token',
            '__token__' => 'fixture-admin-token',
        ]);
        return;
    }

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

if ($method === 'GET' && $path === '/static/admin/js/user/account.js') {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo <<<'JS'
define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/account/index',
        detail_url: 'user/account/detail',
        modify_url: 'user/account/modify'
    };

    return {
        index: function () {
            var canModify = $(init.table_elem).attr('data-auth-modify') === '1';

            ea.table.render({
                init: init,
                cols: [[
                    {field: 'status', title: '状态', templet: '#userStatusTpl'}
                ]]
            });

            $('body').on('click', '[data-account-status]', function () {
                var status = $(this).data('account-status');

                ea.request.post({
                    url: $('[data-user-status-admin]').attr('data-status-endpoint'),
                    data: {
                        id: $(this).data('account-id'),
                        field: 'status',
                        value: status
                    }
                }, function () {
                    ea.table.reload(init.table_render_id);
                });
            });
        }
    };
});
JS;
    return;
}

if ($method === 'GET' && $path === '/static/admin/js/system/module.js') {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo <<<'JS'
define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'system/module/index',
        discover_url: 'system/module/discover',
        upload_url: 'system/module/upload',
        install_url: 'system/module/install',
        approve_url: 'system/module/approve',
        reject_url: 'system/module/reject',
        enable_url: 'system/module/enable',
        disable_url: 'system/module/disable',
        uninstall_url: 'system/module/uninstall',
        upgradeLocal_url: 'system/module/upgradeLocal',
        rollback_url: 'system/module/rollback'
    };

    return {
        index: function () {
            $('body').on('click', '[data-module-action]', function () {
                ea.request.post({url: init.approve_url});
            });
            $('body').on('click', '[data-module-reject]', function () {
                ea.request.post({url: init.reject_url});
            });
        }
    };
});
JS;
    return;
}

if ($method === 'GET' && $path === '/admin/ajax/initAdmin') {
    $menuInfo = [];

    if ($mode !== 'missing-menu') {
        $children = $userOpsChildren($mode);

        $menuInfo[] = [
            'title' => '用户运营',
            'href' => '',
            'child' => $children,
        ];

        if ($mode === 'dashboard-link-outside-user-ops') {
            $menuInfo[] = [
                'title' => 'Legacy',
                'href' => '',
                'child' => [
                    ['title' => 'Archived Dashboard', 'href' => '/admin/archived/user/dashboard/index-disabled'],
                ],
            ];
        }

        $menuInfo[] = [
            'title' => '系统管理',
            'href' => '',
            'child' => [
                ['title' => '模块管理', 'href' => '/admin/system/module/index'],
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

if ($method === 'POST' && $path === '/admin/user/account/modify') {
    $payload = $input();
    $field = (string) ($payload['field'] ?? '');
    $value = (string) ($payload['value'] ?? '');

    if ($field !== 'status') {
        $json([
            'code' => 0,
            'msg' => '用户账号管理仅允许修改账号状态。',
            '__token__' => 'fixture-admin-token-refreshed',
        ]);
        return;
    }

    if (! in_array($value, ['pending', 'active', 'disabled', 'frozen'], true)) {
        $json([
            'code' => 0,
            'msg' => '账号状态值无效。',
            '__token__' => 'fixture-admin-token-refreshed',
        ]);
        return;
    }

    $json([
        'code' => 1,
        'msg' => '保存成功',
        '__token__' => 'fixture-admin-token-refreshed',
    ]);
    return;
}

$userOpsPagePaths = array_values(array_filter(array_map(
    static fn (array $child): ?string => parse_url($child['href'], PHP_URL_PATH) ?: null,
    $userOpsChildren('')
)));

if ($method === 'GET' && $path === '/admin/system/module/index') {
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<'HTML'
<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="fixture-admin-token">
    <title>模块中心</title>
</head>
<body>
<main>
    <h1>模块中心</h1>
    <table id="currentTable" lay-filter="currentTable"></table>
</main>
</body>
</html>
HTML;
    return;
}

if ($method === 'GET' && in_array($path, $userOpsPagePaths, true)) {
    if ($mode === 'page-error' && $path === '/admin/user/account/index') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><body><div class="system-message error"><h1>No permission</h1></div></body></html>';
        return;
    }

    if ($mode === 'login-shell-page' && $path === '/admin/user/account/index') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><title>Admin Login</title><link rel="stylesheet" href="/static/admin/css/login.css"></head><body><form id="loginForm"><input name="username"><input name="password"></form></body></html>';
        return;
    }

    if ($path === '/admin/user/account/index') {
        header('Content-Type: text/html; charset=UTF-8');
        echo <<<'HTML'
<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="fixture-admin-token">
    <title>用户账号管理</title>
</head>
<body>
<main>
    <div class="layui-card" data-user-status-admin
         data-status-endpoint="/admin/user/account/modify"
         data-status-values="pending,active,disabled,frozen">
        <div class="layui-card-header">账号状态管理</div>
        <div class="layui-card-body">
            <span class="layui-badge layui-bg-gray">待审核 pending</span>
            <span class="layui-badge layui-bg-green">正常 active</span>
            <span class="layui-badge">已禁用 disabled</span>
            <span class="layui-badge layui-bg-orange">已冻结 frozen</span>
        </div>
    </div>
    <table id="currentTable" data-auth-detail="1" data-auth-modify="1" lay-filter="currentTable"></table>
    <script type="text/html" id="userStatusTpl">
        <span class="layui-badge layui-bg-gray">待审核</span>
    </script>
</main>
</body>
</html>
HTML;
        return;
    }

    $html($path);
    return;
}

http_response_code(404);
echo 'Not Found';
