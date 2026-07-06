# P13 Module Admin Smoke Implementation Plan

> **Execution note:** Implement directly in the current workspace without subagents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing admin smoke check so deployment QA proves the module management menu, page, and JavaScript action wiring are visible.

**Architecture:** Reuse `scripts/user-admin-smoke.php` because deployment acceptance already logs into EasyAdmin, runs `system:module-menu:sync`, and executes this smoke script. Add non-mutating module center checks after the user-operations checks: menu discovery, `/admin/system/module/index` page shell, and `/static/admin/js/system/module.js` action tokens for discovery, upload, install, review, enable/disable, upgrade, rollback, and uninstall.

**Tech Stack:** Plain PHP smoke script, fixture router, Laravel feature tests, SQLite test runner.

## Global Constraints

- Do not perform module lifecycle mutations during smoke checks.
- Do not add a second admin login smoke script.
- Keep checks dependency-free and compatible with local Windows/PowerShell deployment.

---

### Files

- Modify: `scripts/user-admin-smoke.php`
  - Add module center menu/page/script assertions.
- Modify: `tests/Fixtures/user-admin-smoke-router.php`
  - Add fixture module menu entry, module page shell, and module JS response.
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`
  - Assert successful smoke output includes module center pass lines.
- Modify: `tests/Feature/Modules/ModuleCenterControllerTest.php`
  - Add static contract test proving admin smoke covers module center visibility and JS action wiring.

---

### Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`
- Modify: `tests/Feature/Modules/ModuleCenterControllerTest.php`

**Interfaces:**
- Consumes: existing `scripts/user-admin-smoke.php`.
- Produces: failing tests requiring module center smoke coverage.

- [ ] **Step 1: Add fixture smoke output assertions**

In `test_user_admin_smoke_script_passes_against_fixture_server()`, add:

```php
$this->assertStringContainsString('PASS GET /admin/ajax/initAdmin menu contains 模块管理', $output);
$this->assertStringContainsString('PASS GET /admin/system/module/index module center page', $output);
$this->assertStringContainsString('PASS GET /static/admin/js/system/module.js module actions', $output);
```

- [ ] **Step 2: Add smoke artifact contract test**

Add this test to `tests/Feature/Modules/ModuleCenterControllerTest.php`:

```php
public function test_admin_smoke_script_checks_module_center_visibility_and_actions(): void
{
    $script = file_get_contents(base_path('scripts/user-admin-smoke.php'));

    $this->assertIsString($script);
    $this->assertStringContainsString('expectModuleCenterMenu', $script);
    $this->assertStringContainsString('expectModuleCenterPage', $script);
    $this->assertStringContainsString('expectModuleCenterScript', $script);
    $this->assertStringContainsString('system/module/index', $script);
    $this->assertStringContainsString('/static/admin/js/system/module.js', $script);
    $this->assertStringContainsString('data-module-action', $script);
    $this->assertStringContainsString('data-module-reject', $script);
    $this->assertStringContainsString('approve_url', $script);
    $this->assertStringContainsString('rollback_url', $script);
}
```

- [ ] **Step 3: Run focused tests to verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="test_admin_smoke_script_checks_module_center_visibility_and_actions|test_user_admin_smoke_script_passes_against_fixture_server"
```

Expected: FAIL because the smoke script does not yet include module center checks.

---

### Task 2: GREEN Implementation

**Files:**
- Modify: `scripts/user-admin-smoke.php`
- Modify: `tests/Fixtures/user-admin-smoke-router.php`

**Interfaces:**
- Consumes: `expectBodyContains()`, `expectAdminPageBody()`, `menuContainsHref()`, `findMenuByTitles()`.
- Produces:
  - `expectModuleCenterMenu(array $payload, string $adminPrefix): void`
  - `expectModuleCenterPage(array $response, string $label): void`
  - `expectModuleCenterScript(array $response, string $label): void`

- [ ] **Step 1: Add module center smoke helpers**

Add these functions after `expectMenu()`:

```php
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
```

Add these functions after `expectAccountStatusScript()`:

```php
function expectModuleCenterPage(array $response, string $label): void
{
    expectAdminPageBody($response, $label);
    expectBodyContains($response['body'], '模块中心', $label);
    expectBodyContains($response['body'], 'id="currentTable"', $label);
    expectBodyContains($response['body'], 'lay-filter="currentTable"', $label);
}

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
    ] as $needle) {
        expectBodyContains($response['body'], $needle, $label);
    }
}
```

- [ ] **Step 2: Call module center checks**

After `expectMenu($response['json'], $prefix);`, add:

```php
expectModuleCenterMenu($response['json'], $prefix);
pass('GET /' . $prefix . '/ajax/initAdmin menu contains 模块管理');
```

After the account status endpoint guards, add:

```php
$response = $client->request('GET', adminPath($prefix, 'system/module/index'));
expectModuleCenterPage($response, 'GET /' . $prefix . '/system/module/index module center page');
pass('GET /' . $prefix . '/system/module/index module center page');

$response = $client->request('GET', '/static/admin/js/system/module.js');
expectModuleCenterScript($response, 'GET /static/admin/js/system/module.js');
pass('GET /static/admin/js/system/module.js module actions');
```

- [ ] **Step 3: Add fixture menu and responses**

In `tests/Fixtures/user-admin-smoke-router.php`:

Add a `系统管理` menu entry with `href=/admin/system/module/index` to `/admin/ajax/initAdmin`.

Add `GET /admin/system/module/index` response:

```php
if ($method === 'GET' && $path === '/admin/system/module/index') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="csrf-token" content="fixture-admin-token"><title>模块中心</title></head><body><main><h1>模块中心</h1><table id="currentTable" lay-filter="currentTable"></table></main></body></html>';
    return;
}
```

Add `GET /static/admin/js/system/module.js` response containing the init URLs and `data-module-action`/`data-module-reject` tokens.

- [ ] **Step 4: Run focused tests to verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAdminSmokeScriptTest|ModuleCenterControllerTest"
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

- [ ] **Step 2: Full tests**

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
git diff -- scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminSmokeScriptTest.php tests/Feature/Modules/ModuleCenterControllerTest.php docs/superpowers/plans/2026-07-07-p13-module-admin-smoke.md
```

Expected: diff scope is limited to this P13 task plus the plan file.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p13-module-admin-smoke.md scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminSmokeScriptTest.php tests/Feature/Modules/ModuleCenterControllerTest.php
git commit -m "test: smoke module admin visibility"
git push origin main
```

Expected: push succeeds to `origin/main`.

---

### Self-Review

- Spec coverage: P13 covers module management menu visibility, page shell, JS lifecycle/review action wiring, fixture parity, verification, commit, and push.
- Placeholder scan: no placeholders remain.
- Scope check: the smoke checks are read-only and do not execute module lifecycle changes.
