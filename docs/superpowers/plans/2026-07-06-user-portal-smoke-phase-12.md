# User Portal Smoke Automation Phase 12 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a repeatable HTTP smoke script that verifies the visible user portal pages and register/login/session/dashboard/logout flow against a running server.

**Architecture:** Implement a standalone PHP script with a tiny HTTP client, cookie jar, CSRF parsing, JSON assertions, and readable pass/fail output. Cover it with PHPUnit by running the script against a fixture PHP built-in server, then verify it against a real Laravel local server using SQLite.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12, Symfony Process from existing dependencies, PHP built-in development server, SQLite test runtime.

---

## File Structure

- Create `scripts/user-portal-smoke.php`
  - Standalone CLI smoke script. Owns option parsing, HTTP requests, cookie persistence, CSRF token parsing, JSON assertions, and pass/fail output.
- Create `tests/Fixtures/user-portal-smoke-router.php`
  - Lightweight fixture app for testing script behavior without booting Laravel.
- Create `tests/Feature/User/UserPortalSmokeScriptTest.php`
  - PHPUnit coverage for success and clear failure output.
- Modify `composer.json`
  - Add `"smoke:user-portal": "@php scripts/user-portal-smoke.php"` under `scripts`.
- Keep generated/local files untracked
  - Do not add `composer.lock`, `.env`, `database/database.sqlite`, `vendor/`, or `config/install/lock/install.lock`.

---

## Task 1: Smoke Script Tests And Fixture

**Files:**

- Create: `tests/Feature/User/UserPortalSmokeScriptTest.php`
- Create: `tests/Fixtures/user-portal-smoke-router.php`
- Create initially failing placeholder: `scripts/user-portal-smoke.php`

- [ ] **Step 1: Add a minimal placeholder script**

Create `scripts/user-portal-smoke.php`:

```php
<?php

fwrite(STDERR, "FAIL user portal smoke failed\nSmoke script is not implemented.\n");
exit(1);
```

- [ ] **Step 2: Add fixture router**

Create `tests/Fixtures/user-portal-smoke-router.php`:

```php
<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mode = getenv('SMOKE_FIXTURE_MODE') ?: 'ok';
$sessionId = $_COOKIE['SMOKESESSID'] ?? bin2hex(random_bytes(8));
$stateFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ea8_smoke_'.$sessionId.'.json';

header('Set-Cookie: SMOKESESSID='.$sessionId.'; Path=/; HttpOnly');

$loadState = static function () use ($stateFile): array {
    if (! is_file($stateFile)) {
        return ['logged_in' => false, 'email' => null];
    }

    return json_decode((string) file_get_contents($stateFile), true) ?: ['logged_in' => false, 'email' => null];
};

$saveState = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
};

$page = static function (string $title): void {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta name="csrf-token" content="fixture-token"></head><body>';
    echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>';
    echo '</body></html>';
};

if ($path === '/u') {
    http_response_code(302);
    header('Location: /u/dashboard');
    return;
}

if (in_array($path, ['/u/login', '/u/register', '/u/forgot-password', '/u/reset-password', '/u/dashboard'], true)) {
    $page($path);
    return;
}

if ($mode === 'broken-session' && $path === '/user/session') {
    $json(['code' => 1, 'msg' => 'User session', 'data' => []]);
    return;
}

if ($path === '/user/register' && $method === 'POST') {
    parse_str((string) file_get_contents('php://input'), $body);
    $email = (string) ($body['email'] ?? 'fixture@example.com');
    $json(['code' => 1, 'msg' => 'registered', 'data' => ['user' => ['email' => $email]], '__token__' => 'fixture-token-2']);
    return;
}

if ($path === '/user/login' && $method === 'POST') {
    parse_str((string) file_get_contents('php://input'), $body);
    $email = (string) ($body['account'] ?? 'fixture@example.com');
    $saveState(['logged_in' => true, 'email' => $email]);
    $json(['code' => 1, 'msg' => 'logged in', 'data' => ['user' => ['email' => $email]], '__token__' => 'fixture-token-3']);
    return;
}

if ($path === '/user/logout' && $method === 'POST') {
    $saveState(['logged_in' => false, 'email' => null]);
    $json(['code' => 1, 'msg' => 'logged out', 'data' => [], '__token__' => 'fixture-token-4']);
    return;
}

if ($path === '/user/session') {
    $state = $loadState();
    if (! $state['logged_in']) {
        $json(['code' => 0, 'msg' => 'User login required.', 'data' => [], '__token__' => 'fixture-token']);
        return;
    }

    $json(['code' => 1, 'msg' => 'User session', 'data' => ['user' => ['email' => $state['email']]], '__token__' => 'fixture-token']);
    return;
}

if (in_array($path, ['/user/vip', '/user/balance', '/user/balance/ledger', '/user/invite', '/user/invite/records', '/user/withdrawal'], true)) {
    $state = $loadState();
    $json([
        'code' => $state['logged_in'] ? 1 : 0,
        'msg' => $state['logged_in'] ? 'ok' : 'User login required.',
        'data' => [],
        '__token__' => 'fixture-token',
    ]);
    return;
}

http_response_code(404);
echo 'not found';
```

- [ ] **Step 3: Add failing PHPUnit tests**

Create `tests/Feature/User/UserPortalSmokeScriptTest.php`:

```php
<?php

namespace Tests\Feature\User;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserPortalSmokeScriptTest extends TestCase
{
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if ($server instanceof Process && $server->isRunning()) {
                $server->stop(0.2);
            }
        }

        parent::tearDown();
    }

    public function test_user_portal_smoke_script_passes_against_fixture_server(): void
    {
        [$server, $baseUrl] = $this->startFixtureServer();
        $this->servers[] = $server;

        $process = $this->runSmoke($baseUrl, 'smoke-success@example.com');

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK user portal smoke passed', $process->getOutput());
        $this->assertStringContainsString('/user/session logged in', $process->getOutput());
    }

    public function test_user_portal_smoke_script_fails_with_clear_message_for_bad_session_payload(): void
    {
        [$server, $baseUrl] = $this->startFixtureServer(['SMOKE_FIXTURE_MODE' => 'broken-session']);
        $this->servers[] = $server;

        $process = $this->runSmoke($baseUrl, 'smoke-failure@example.com');

        $this->assertNotSame(0, $process->getExitCode());
        $combined = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('FAIL user portal smoke failed', $combined);
        $this->assertStringContainsString('Session response missing matching user email', $combined);
    }

    private function startFixtureServer(array $env = []): array
    {
        $port = $this->freePort();
        $router = base_path('tests/Fixtures/user-portal-smoke-router.php');
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], base_path(), array_merge($_ENV, $env));
        $server->start();

        $baseUrl = 'http://127.0.0.1:'.$port;
        $deadline = microtime(true) + 5;

        do {
            if (@file_get_contents($baseUrl.'/u/register') !== false) {
                return [$server, $baseUrl];
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->fail('Fixture server did not start: '.$server->getErrorOutput());
    }

    private function runSmoke(string $baseUrl, string $email): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/user-portal-smoke.php'),
            '--base-url='.$baseUrl,
            '--email='.$email,
            '--password=secret123',
            '--timeout=5',
        ], base_path());
        $process->run();

        return $process;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr((string) $name, ':'), 1);
    }
}
```

- [ ] **Step 4: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalSmokeScriptTest.php
```

Expected: FAIL because the placeholder script exits 1 and does not perform the smoke flow.

- [ ] **Step 5: Commit RED test checkpoint**

Run:

```powershell
git add scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php
git commit -m "test: add user portal smoke script coverage"
```

---

## Task 2: Implement Smoke Script

**Files:**

- Modify: `scripts/user-portal-smoke.php`
- Modify: `composer.json`
- Test: `tests/Feature/User/UserPortalSmokeScriptTest.php`

- [ ] **Step 1: Replace placeholder with the smoke script implementation**

Implement `scripts/user-portal-smoke.php` with these responsibilities:

```php
<?php

declare(strict_types=1);

$options = getopt('', ['base-url:', 'email::', 'password::', 'timeout::']);
$baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
$email = (string) ($options['email'] ?? ('smoke+'.date('YmdHis').random_int(1000, 9999).'@example.com'));
$password = (string) ($options['password'] ?? 'secret123');
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$cookies = [];
$csrf = null;

$steps = [];

$fail = static function (string $message) use (&$steps): never {
    fwrite(STDERR, "FAIL user portal smoke failed\n".$message."\n");
    if ($steps !== []) {
        fwrite(STDERR, "Completed steps:\n- ".implode("\n- ", $steps)."\n");
    }
    exit(1);
};

$pass = static function (string $step) use (&$steps): void {
    $steps[] = $step;
    echo "PASS ".$step.PHP_EOL;
};

if ($baseUrl === '') {
    $fail('Missing required --base-url option.');
}

$request = function (string $method, string $path, array $payload = []) use (&$cookies, &$csrf, $baseUrl, $timeout, $fail): array {
    $url = $baseUrl.$path;
    $headers = ['Accept: application/json, text/html;q=0.9'];
    $body = null;

    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }
        $headers[] = 'Cookie: '.implode('; ', $pairs);
    }

    if ($method !== 'GET') {
        $body = http_build_query($payload);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($csrf !== null && $csrf !== '') {
            $headers[] = 'X-CSRF-TOKEN: '.$csrf;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        $fail('HTTP request failed: '.$method.' '.$path);
    }

    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int) $match[1];
        }
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $parts = explode(';', $cookie);
            [$name, $value] = array_pad(explode('=', $parts[0], 2), 2, '');
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => (string) $responseBody,
        'json' => json_decode((string) $responseBody, true),
    ];
};

$expectStatus = static function (array $response, array $allowed, string $label) use ($fail): void {
    if (! in_array($response['status'], $allowed, true)) {
        $fail($label.' returned HTTP '.$response['status'].', expected '.implode('/', $allowed).'.');
    }
};

$expectJsonCode = static function (array $response, int $code, string $label) use (&$csrf, $fail): array {
    if (! is_array($response['json'])) {
        $fail($label.' did not return JSON.');
    }
    if ((int) ($response['json']['code'] ?? -999) !== $code) {
        $fail($label.' returned code '.var_export($response['json']['code'] ?? null, true).', expected '.$code.'.');
    }
    if (! empty($response['json']['__token__'])) {
        $csrf = (string) $response['json']['__token__'];
    }

    return $response['json'];
};

$registerPage = $request('GET', '/u/register');
$expectStatus($registerPage, [200], '/u/register');
if (preg_match('/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i', $registerPage['body'], $match)) {
    $csrf = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}
if ($csrf === null || $csrf === '') {
    $fail('Could not find portal CSRF token.');
}
$pass('/u/register csrf loaded');

foreach ([
    '/u' => [200, 302],
    '/u/login' => [200],
    '/u/register' => [200],
    '/u/forgot-password' => [200],
    '/u/reset-password' => [200],
    '/u/dashboard' => [200],
] as $path => $allowedStatuses) {
    $response = $request('GET', $path);
    $expectStatus($response, $allowedStatuses, $path);
    $pass($path.' reachable');
}

$session = $request('GET', '/user/session');
$expectStatus($session, [200], '/user/session logged out');
$expectJsonCode($session, 0, '/user/session logged out');
$pass('/user/session logged out');

$registered = $request('POST', '/user/register', ['email' => $email, 'password' => $password]);
$expectStatus($registered, [200], '/user/register');
$expectJsonCode($registered, 1, '/user/register');
$pass('/user/register');

$loggedIn = $request('POST', '/user/login', ['account' => $email, 'password' => $password]);
$expectStatus($loggedIn, [200], '/user/login');
$expectJsonCode($loggedIn, 1, '/user/login');
$pass('/user/login');

$session = $request('GET', '/user/session');
$expectStatus($session, [200], '/user/session logged in');
$sessionJson = $expectJsonCode($session, 1, '/user/session logged in');
if (($sessionJson['data']['user']['email'] ?? null) !== $email) {
    $fail('Session response missing matching user email.');
}
$pass('/user/session logged in');

foreach (['/user/vip', '/user/balance', '/user/balance/ledger', '/user/invite', '/user/invite/records', '/user/withdrawal'] as $path) {
    $response = $request('GET', $path);
    $expectStatus($response, [200], $path);
    $expectJsonCode($response, 1, $path);
    $pass($path);
}

$logout = $request('POST', '/user/logout');
$expectStatus($logout, [200], '/user/logout');
$expectJsonCode($logout, 1, '/user/logout');
$pass('/user/logout');

$session = $request('GET', '/user/session');
$expectStatus($session, [200], '/user/session after logout');
$expectJsonCode($session, 0, '/user/session after logout');
$pass('/user/session after logout');

echo "OK user portal smoke passed\n";
exit(0);
```

- [ ] **Step 2: Add Composer script alias**

In `composer.json`, add after `test:sqlite`:

```json
"smoke:user-portal": "@php scripts/user-portal-smoke.php",
```

Keep JSON valid by adding a comma after `test:sqlite`.

- [ ] **Step 3: Verify GREEN for smoke script tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalSmokeScriptTest.php
```

Expected: PASS with 2 tests.

- [ ] **Step 4: Syntax check**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-portal-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-portal-smoke-router.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserPortalSmokeScriptTest.php
```

Expected: no syntax errors.

- [ ] **Step 5: Commit implementation**

Run:

```powershell
git add scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php composer.json
git commit -m "feat: add user portal smoke script"
```

---

## Task 3: Real Laravel Smoke Verification And Review

**Files:**

- No production code changes expected.
- Review: `scripts/user-portal-smoke.php`, `tests/Fixtures/user-portal-smoke-router.php`, `tests/Feature/User/UserPortalSmokeScriptTest.php`, `composer.json`

- [ ] **Step 1: Run focused portal tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalSmokeScriptTest.php tests\Feature\User\UserPortalPageTest.php tests\Feature\User\UserPortalFlowHardeningTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full SQLite suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: `OK (266 tests, ...)` or higher.

- [ ] **Step 3: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-portal-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-portal-smoke-router.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserPortalSmokeScriptTest.php
node --check public\static\user\js\portal.js
git diff --check
```

Expected: clean.

- [ ] **Step 4: Run real Laravel local smoke**

Prepare SQLite and start the Laravel server from this worktree:

```powershell
$env:APP_ENV='local'
$env:APP_URL='http://127.0.0.1:8012'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE='E:\code\user\EasyAdmin8-Laravel\.worktrees\p12-user-portal-smoke\database\database.sqlite'
$env:SESSION_DRIVER='file'
New-Item -ItemType File -Path database\database.sqlite -Force | Out-Null
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:fresh --force
Start-Process -FilePath E:\code\user\.tools\php-8.3.32\php.exe -ArgumentList @('-d','extension=pdo_sqlite','-d','extension=sqlite3','artisan','serve','--host=127.0.0.1','--port=8012') -WorkingDirectory E:\code\user\EasyAdmin8-Laravel\.worktrees\p12-user-portal-smoke -WindowStyle Hidden
E:\code\user\.tools\php-8.3.32\php.exe scripts\user-portal-smoke.php --base-url=http://127.0.0.1:8012
```

Expected: output includes `OK user portal smoke passed`.

After the smoke run, stop the `artisan serve` process for port `8012`.

- [ ] **Step 5: Review checklist**

Confirm:

- The script uses real HTTP requests, not Laravel internals.
- The script preserves cookies across the flow.
- The script sends CSRF for POST requests.
- The checked endpoints match the P12 design.
- Failures include a clear `FAIL user portal smoke failed` header and the failing reason.
- No secrets, local `.env`, generated SQLite DB, `composer.lock`, or `vendor` files are staged.

- [ ] **Step 6: Request spec compliance and code quality review**

Dispatch reviewer agents with:

- Requirement source: `docs/superpowers/specs/2026-07-06-user-portal-smoke-design.md`
- Plan source: `docs/superpowers/plans/2026-07-06-user-portal-smoke-phase-12.md`
- Diff range: base commit before Task 1 through current HEAD.

Critical/Important review findings must be fixed and re-reviewed before completion.

- [ ] **Step 7: Commit review checkpoint**

If review finds no code changes are needed:

```powershell
git commit --allow-empty -m "chore: review user portal smoke phase"
```

If review fixes are needed:

```powershell
git add <fixed files>
git commit -m "fix: address user portal smoke review"
git commit --allow-empty -m "chore: review user portal smoke phase"
```

---

## Finalization

- Merge `p12-user-portal-smoke` into `main`.
- Re-run focused tests, full SQLite suite, static checks, and the script fixture test on merged `main`.
- Push `main` to `origin`.
- Report commits, verification output, and the next recommended P.

---

## Plan Self-Review

- Spec coverage: script contract, page checks, CSRF, cookies, register/login/session/dashboard/logout, fixture tests, real Laravel smoke, reviews, merge, and push are covered.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: file paths, endpoint paths, script options, Composer script name, and test class names match across tasks.
- Scope guard: no business rules, frontend redesign, external provider integrations, or new dependencies are included.
