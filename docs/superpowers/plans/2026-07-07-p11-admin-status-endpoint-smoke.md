# P11 Admin User Status Endpoint Smoke Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the admin user smoke script so deployment checks prove the account status modify endpoint is reachable and still rejects unsafe account mutations.

**Architecture:** Add non-mutating POST probes to `scripts/user-admin-smoke.php` after the UI and JavaScript checks. The probes use authenticated AJAX requests with CSRF and assert JSON `code=0` for non-status fields and invalid status values, proving the endpoint exists while preserving the P7 status-only backend boundary.

**Tech Stack:** Plain PHP smoke script, fixture router, Laravel feature tests, SQLite test runner.

## Global Constraints

- Do not mutate real user accounts during smoke checks.
- Do not enable general account add/edit/delete behavior.
- Keep the smoke script dependency-free and runnable with PHP only.

---

### Files

- Modify: `scripts/user-admin-smoke.php`
  - Add `expectJsonMessageContains()`.
  - Add `expectAccountStatusEndpointGuards()`.
  - Call endpoint guard smoke after account status UI/JS checks.
- Modify: `tests/Fixtures/user-admin-smoke-router.php`
  - Add fixture response for `POST /admin/user/account/modify`.
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
  - Add a static contract test proving the smoke script includes safe endpoint probes.
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`
  - Assert successful fixture smoke output includes the new endpoint guard pass message.

---

### Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`

**Interfaces:**
- Consumes: existing `scripts/user-admin-smoke.php` artifact.
- Produces: failing tests that require endpoint guard smoke coverage.

- [ ] **Step 1: Add smoke script contract test**

Add this test after `test_user_admin_smoke_script_checks_account_status_ui_and_js()`:

```php
public function test_user_admin_smoke_script_checks_account_status_endpoint_guards(): void
{
    $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

    $this->assertIsString($script);
    $this->assertStringContainsString('expectAccountStatusEndpointGuards', $script);
    $this->assertStringContainsString("adminPath($prefix, 'user/account/modify')", $script);
    $this->assertStringContainsString("'field' => 'nickname'", $script);
    $this->assertStringContainsString("'field' => 'status'", $script);
    $this->assertStringContainsString("'value' => 'archived'", $script);
    $this->assertStringContainsString('用户账号管理仅允许修改账号状态', $script);
    $this->assertStringContainsString('账号状态值无效', $script);
}
```

- [ ] **Step 2: Add fixture smoke output assertion**

In `tests/Feature/User/UserAdminSmokeScriptTest.php`, add this assertion to `test_user_admin_smoke_script_passes_against_fixture_server()` after the existing status/UI output assertions:

```php
$this->assertStringContainsString('PASS POST /admin/user/account/modify status endpoint guards', $output);
```

- [ ] **Step 3: Run focused tests to verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="test_user_admin_smoke_script_checks_account_status_endpoint_guards|test_user_admin_smoke_script_passes_against_fixture_server"
```

Expected: FAIL because the smoke script does not yet include endpoint guard probes or pass output.

---

### Task 2: GREEN Implementation

**Files:**
- Modify: `scripts/user-admin-smoke.php`
- Modify: `tests/Fixtures/user-admin-smoke-router.php`

**Interfaces:**
- Consumes: `AdminSmokeHttpClient::request()`, `expectJsonCode()`, `adminPath()`.
- Produces: `expectAccountStatusEndpointGuards(AdminSmokeHttpClient $client, string $prefix): void`.

- [ ] **Step 1: Add JSON message helper**

Add this helper after `expectJsonCode()`:

```php
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
```

- [ ] **Step 2: Add endpoint guard smoke**

Add this function near `expectAccountStatusScript()`:

```php
function expectAccountStatusEndpointGuards(AdminSmokeHttpClient $client, string $prefix): void
{
    $label = 'POST /' . $prefix . '/user/account/modify status endpoint guards';

    $response = $client->request('POST', adminPath($prefix, 'user/account/modify'), [
        'id' => '1',
        'field' => 'nickname',
        'value' => 'Smoke Probe',
    ], ajax: true, jsonAccept: true);
    expectStatus($response, [200], $label . ' non-status field');
    expectJsonCode($response, 0, $label . ' non-status field');
    expectJsonMessageContains($response, '用户账号管理仅允许修改账号状态', $label . ' non-status field');

    $response = $client->request('POST', adminPath($prefix, 'user/account/modify'), [
        'id' => '1',
        'field' => 'status',
        'value' => 'archived',
    ], ajax: true, jsonAccept: true);
    expectStatus($response, [200], $label . ' invalid status');
    expectJsonCode($response, 0, $label . ' invalid status');
    expectJsonMessageContains($response, '账号状态值无效', $label . ' invalid status');
}
```

- [ ] **Step 3: Call endpoint guard smoke**

After `expectAccountStatusScript(...)` and its pass line, add:

```php
expectAccountStatusEndpointGuards($client, $prefix);
pass('POST /' . $prefix . '/user/account/modify status endpoint guards');
```

- [ ] **Step 4: Add fixture route**

In `tests/Fixtures/user-admin-smoke-router.php`, after the dashboard JSON route and before generic user ops pages, add:

```php
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
```

- [ ] **Step 5: Run focused tests to verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAdminAccountControllerTest|UserAdminSmokeScriptTest"
```

Expected: PASS.

---

### Task 3: Verification, Review, Commit, Push

**Files:**
- Review all modified files.

**Interfaces:**
- Consumes: final diff and verification commands.
- Produces: pushed commit on `origin/main`.

- [ ] **Step 1: PHP syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts/user-admin-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Fixtures/user-admin-smoke-router.php
```

Expected: no syntax errors.

- [ ] **Step 2: Run full SQLite suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diff**

Run:

```powershell
git diff --check
git diff --stat
git diff -- scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminAccountControllerTest.php tests/Feature/User/UserAdminSmokeScriptTest.php docs/superpowers/plans/2026-07-07-p11-admin-status-endpoint-smoke.md
```

Expected: diff scope is limited to this P11 task plus the plan file.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p11-admin-status-endpoint-smoke.md scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminAccountControllerTest.php tests/Feature/User/UserAdminSmokeScriptTest.php
git commit -m "test: smoke admin user status endpoint guards"
git push origin main
```

Expected: push succeeds to `origin/main`.

---

### Self-Review

- Spec coverage: P11 proves smoke coverage for endpoint reachability, non-status field rejection, invalid status rejection, fixture parity, verification, commit, and push.
- Placeholder scan: no placeholders remain.
- Scope check: this plan is safe for deployment smoke because it performs only rejected POST probes and does not mutate real user accounts.
