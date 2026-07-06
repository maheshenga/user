# P6 Admin Ops Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure the user operations admin backend has a complete visible menu and a repeatable smoke check for every user operations entry.

**Architecture:** Keep the existing menu sync service and smoke-script style. Expand `scripts/user-admin-smoke.php` to validate all expected user operations menu children under the `用户运营` parent and to request every corresponding admin page.

**Tech Stack:** PHP CLI smoke script, Laravel feature tests, Symfony Process fixture server, existing EasyAdmin user operations controllers.

---

## File Structure

- Modify: `scripts/user-admin-smoke.php`
  - Add one canonical expected user operations entry list.
  - Validate all 14 menu child links under the `用户运营` parent.
  - Request every expected admin page after dashboard JSON metrics pass.
- Modify: `tests/Fixtures/user-admin-smoke-router.php`
  - Fixture menu returns the full 14-entry child list.
  - Existing failure modes remove or misplace one child link for negative tests.
  - Fixture serves HTML for every expected user operations page.
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`
  - Assert success output includes a deep page such as `user/settings/index`.
  - Add a negative test for a missing non-dashboard menu child.
  - Keep existing missing-dashboard-link and error-shell coverage.
- Modify: `docs/superpowers/plans/2026-07-07-p6-admin-ops-coverage.md`
  - This plan and review evidence.

---

## Expected User Operations Entries

The smoke script should validate these paths under the `用户运营` menu parent:

```php
[
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
]
```

---

## Task 1: RED Tests For Complete Admin Smoke Coverage

**Files:**
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`

- [ ] **Step 1: Strengthen success assertion**

In `test_user_admin_smoke_script_passes_against_fixture_server()`, add:

```php
$this->assertStringContainsString('PASS GET /admin/user/settings/index', $output);
$this->assertStringContainsString('PASS GET /admin/user/activation-code/redemptions', $output);
```

- [ ] **Step 2: Add missing child negative test**

Add:

```php
public function test_user_admin_smoke_script_fails_when_any_user_ops_child_link_is_missing(): void
{
    $baseUrl = $this->startFixtureServer('missing-settings-link');

    $process = $this->runSmokeScript($baseUrl);
    $output = $process->getOutput() . $process->getErrorOutput();

    $this->assertNotSame(0, $process->getExitCode(), $output);
    $this->assertStringContainsString('FAIL user admin smoke failed', $output);
    $this->assertStringContainsString('Menu response missing user/settings/index under 用户运营', $output);
}
```

- [ ] **Step 3: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php --filter "passes_against_fixture|any_user_ops_child"
```

Expected: FAIL because the fixture and smoke script currently cover only a subset of admin pages.

---

## Task 2: Expand Fixture Menu And Pages

**Files:**
- Modify: `tests/Fixtures/user-admin-smoke-router.php`

- [ ] **Step 1: Add fixture entry helper**

Near the existing helper closures, add:

```php
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

    if ($mode === 'missing-dashboard-link') {
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
```

- [ ] **Step 2: Use helper in menu response**

Replace the hard-coded `$children` array in `/admin/ajax/initAdmin` with:

```php
$children = $userOpsChildren($mode);
```

Keep the `dashboard-link-outside-user-ops` mode placing the archived dashboard outside the user ops parent and removing dashboard from `$children`.

- [ ] **Step 3: Serve every expected admin page**

Replace the hard-coded page path array with:

```php
$userOpsPagePaths = array_map(
    static fn (array $child): string => parse_url($child['href'], PHP_URL_PATH),
    $userOpsChildren('')
);
```

Then use `$userOpsPagePaths` in the `in_array($path, ...)` check.

- [ ] **Step 4: Verify fixture syntax**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-admin-smoke-router.php
```

Expected: no syntax errors.

---

## Task 3: Expand Smoke Script Menu And Page Coverage

**Files:**
- Modify: `scripts/user-admin-smoke.php`

- [ ] **Step 1: Add expected path function**

Add before `expectMenu()`:

```php
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
```

- [ ] **Step 2: Update `expectMenu()`**

Replace the single dashboard-link check with:

```php
foreach (expectedUserOpsPaths() as $path) {
    if (! menuContainsHref($userOpsMenu, $path, $adminPrefix)) {
        throw new AdminSmokeFailure("Menu response missing {$path} under 用户运营.");
    }
}
```

- [ ] **Step 3: Update page request loop**

Replace the current five-page loop with:

```php
foreach (expectedUserOpsPaths() as $path) {
    $response = $client->request('GET', adminPath($prefix, $path));
    expectStatus($response, [200], 'GET /' . $prefix . '/' . $path);
    expectAdminPageBody($response, 'GET /' . $prefix . '/' . $path);
    pass('GET /' . $prefix . '/' . $path);
}
```

- [ ] **Step 4: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-admin-smoke.php
```

Expected: PASS.

---

## Task 4: Full Verification, Review, Commit, Push

**Files:**
- Review: `scripts/user-admin-smoke.php`
- Review: `tests/Fixtures/user-admin-smoke-router.php`
- Review: `tests/Feature/User/UserAdminSmokeScriptTest.php`
- Review: this plan

- [ ] **Step 1: Run focused admin ops tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php tests\Feature\User\UserOpsVisibilityTest.php
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-admin-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-admin-smoke-router.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserAdminSmokeScriptTest.php
git diff --check
git diff --stat
git diff
```

Expected: clean review with no unrelated changes.

- [ ] **Step 3: Run full SQLite suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 4: Commit and push**

```powershell
git add docs/superpowers/plans/2026-07-07-p6-admin-ops-coverage.md scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminSmokeScriptTest.php
git commit -m "test: expand user admin smoke coverage"
git push origin main
```

---

## Self-Review

- Spec coverage: Validates every expected user operations menu child and every corresponding admin page.
- Placeholder scan: No TODO, TBD, or incomplete sections.
- Type consistency: Expected path list matches `UserOpsMenuService` and `UserOpsVisibilityTest::expectedMenuEntries()`.
- Scope guard: No business logic, migrations, or admin controller behavior changes are included.
