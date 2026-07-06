# P10 Admin User Status Smoke Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing admin user smoke script so a local deployment check proves the user account status UI is visible and wired to the status-only backend endpoint.

**Architecture:** Keep `scripts/user-admin-smoke.php` as a no-dependency HTTP smoke runner. Add focused HTML/JavaScript assertions for the account page and controller script after the existing user ops page checks, using the same authenticated cookie session and base URL.

**Tech Stack:** Plain PHP smoke script, Laravel local server, EasyAdmin Blade/AMD JavaScript, SQLite test runner for regression coverage.

---

### Files

- Modify: `scripts/user-admin-smoke.php`
  - Add account status page assertions.
  - Add static JavaScript assertions for `admin/js/user/account.js`.
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
  - Add a regression test that treats the smoke script as an artifact and asserts it contains the account status smoke checks.

---

### Task 1: RED Regression Test

**Files:**
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Add failing smoke-script artifact test**

Add this test method after `test_admin_user_account_js_wires_status_table_actions()`:

```php
public function test_user_admin_smoke_script_checks_account_status_ui_and_js(): void
{
    $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

    $this->assertIsString($script);
    $this->assertStringContainsString('expectAccountStatusPage', $script);
    $this->assertStringContainsString('expectAccountStatusScript', $script);
    $this->assertStringContainsString('data-status-endpoint="/admin/user/account/modify"', $script);
    $this->assertStringContainsString('data-auth-modify=', $script);
    $this->assertStringContainsString('id="userStatusTpl"', $script);
    $this->assertStringContainsString('data-account-status', $script);
    $this->assertStringContainsString("field: 'status'", $script);
    $this->assertStringContainsString('value: status', $script);
}
```

- [ ] **Step 2: Run the focused test to verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter=test_user_admin_smoke_script_checks_account_status_ui_and_js
```

Expected: FAIL because the smoke script does not yet define `expectAccountStatusPage` or `expectAccountStatusScript`.

---

### Task 2: GREEN Smoke Coverage

**Files:**
- Modify: `scripts/user-admin-smoke.php`

- [ ] **Step 1: Add a body contains helper**

Add this helper near the existing expectation helpers:

```php
function expectBodyContains(string $body, string $needle, string $label): void
{
    if (! str_contains($body, $needle)) {
        throw new AdminSmokeFailure("{$label} missing expected content: {$needle}");
    }
}
```

- [ ] **Step 2: Add account status page assertion**

Add this function near `expectAdminPageBody()`:

```php
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
```

- [ ] **Step 3: Add account status script assertion**

Add this function near `expectAccountStatusPage()`:

```php
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
```

- [ ] **Step 4: Call the new smoke assertions**

After the loop over `expectedUserOpsPaths()`, add:

```php
$response = $client->request('GET', adminPath($prefix, 'user/account/index'));
expectAccountStatusPage($response, 'GET /' . $prefix . '/user/account/index account status UI');
pass('GET /' . $prefix . '/user/account/index account status UI');

$response = $client->request('GET', '/static/admin/js/user/account.js');
expectAccountStatusScript($response, 'GET /static/admin/js/user/account.js');
pass('GET /static/admin/js/user/account.js status actions');
```

- [ ] **Step 5: Run focused tests to verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter=UserAdminAccountControllerTest
```

Expected: PASS.

---

### Task 3: Verification, Review, Commit, Push

**Files:**
- Review all modified files.

- [ ] **Step 1: PHP syntax check**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts/user-admin-smoke.php
```

Expected: no syntax errors.

- [ ] **Step 2: Run full SQLite suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diff and whitespace**

Run:

```powershell
git diff --check
git diff --stat
git diff -- scripts/user-admin-smoke.php tests/Feature/User/UserAdminAccountControllerTest.php docs/superpowers/plans/2026-07-07-p10-admin-status-smoke.md
```

Expected: `git diff --check` exits 0 and diff scope is limited to this P10 task plus the plan file.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p10-admin-status-smoke.md scripts/user-admin-smoke.php tests/Feature/User/UserAdminAccountControllerTest.php
git commit -m "test: smoke admin user status actions"
git push origin main
```

Expected: push succeeds to `origin/main`.

---

### Self-Review

- Spec coverage: P10 proves the smoke script checks the account status UI, endpoint metadata, permission metadata, status template, status action JavaScript, status-only payload, and table reload behavior.
- Placeholder scan: no placeholder steps remain.
- Scope check: this plan does not add new runtime behavior; it strengthens deployment verification around already implemented P7-P9 behavior.
