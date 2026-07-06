<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? '用户中心' }} - 用户中心</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #fff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #d8dee6;
            --primary: #1f6feb;
            --danger: #b42318;
            --ok: #067647;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, "Microsoft YaHei", sans-serif;
            font-size: 14px;
            line-height: 1.45;
        }
        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .portal-header {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }
        .portal-header-inner,
        .portal-main {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }
        .portal-header-inner {
            min-height: 58px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .portal-brand {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }
        .portal-nav {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .portal-main { padding: 24px 0 40px; }
        .page-title {
            margin: 0 0 18px;
            font-size: 22px;
            font-weight: 700;
        }
        .auth-shell {
            max-width: 520px;
        }
        .auth-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .auth-intro {
            margin: -6px 0 18px;
            color: var(--muted);
        }
        .form-tip {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 400;
        }
        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 16px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        label {
            display: block;
            margin: 0 0 12px;
            color: var(--muted);
            font-weight: 600;
        }
        input, textarea {
            width: 100%;
            display: block;
            margin-top: 6px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px 11px;
            color: var(--text);
            background: #fff;
            font: inherit;
        }
        button {
            border: 0;
            border-radius: 6px;
            padding: 10px 14px;
            color: #fff;
            background: var(--primary);
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button[disabled] {
            cursor: not-allowed;
            opacity: 0.7;
        }
        button.secondary { background: #475467; }
        .status {
            margin-top: 12px;
            min-height: 20px;
            color: var(--muted);
            white-space: pre-wrap;
        }
        .status.ok { color: var(--ok); }
        .status.error { color: var(--danger); }
        .data-box {
            margin-top: 10px;
            min-height: 44px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px;
            background: #fbfcfe;
            color: var(--muted);
            white-space: pre-wrap;
        }
        .muted { color: var(--muted); }
        @media (max-width: 640px) {
            .portal-header-inner {
                align-items: flex-start;
                flex-direction: column;
                padding: 12px 0;
            }
        }
    </style>
</head>
<body>
<header class="portal-header">
    <div class="portal-header-inner">
        <a class="portal-brand" href="/u/dashboard">用户中心</a>
        <nav class="portal-nav">
            <a href="/u/login">登录</a>
            <a href="/u/register">注册</a>
            <a href="/u/forgot-password">找回密码</a>
            <a href="/u/dashboard">控制台</a>
        </nav>
    </div>
</header>
<main class="portal-main">
    @yield('content')
</main>
<script src="/static/user/js/portal.js"></script>
</body>
</html>
