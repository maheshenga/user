# Module Admin Smoke Production Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the fixture-backed module admin smoke contract match the real production Blade view without weakening blank-page detection.

**Architecture:** The smoke script remains a black-box HTTP verifier. The fixture will reproduce the real module page shell, and the verifier will rely on authenticated-page checks plus the table's stable identifiers; lifecycle action coverage remains in the existing JavaScript assertion.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12, EasyAdmin Blade/Layui views.

## Global Constraints

- Do not change module lifecycle behavior, permissions, routes, or Blade layout.
- Do not use subagents for this task.
- Preserve the `currentTable` and module JavaScript action checks.
- Use the Windows PHP 8.3 runtime at `E:\code\user\.tools\php-8.3.32\php.exe`.

---

### Task 1: Align the smoke fixture and page contract

**Files:**
- Modify: `tests/Fixtures/user-admin-smoke-router.php:330-345`
- Modify: `scripts/user-admin-smoke.php:499-505`
- Test: `tests/Feature/User/UserAdminSmokeScriptTest.php`
- Test: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Test: `tests/Feature/User/DeployAcceptanceScriptTest.php`

**Interfaces:**
- Consumes: `expectAdminPageBody(array $response, string $label): void`
- Produces: `expectModuleCenterPage(array $response, string $label): void` that validates the real module table shell without requiring a synthetic heading.

- [ ] **Step 1: Make the fixture reproduce the real Blade page**

Remove the synthetic title and heading while preserving the authenticated HTML shell and table:

```php
<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="fixture-admin-token">
</head>
<body>
<main>
    <table id="currentTable" lay-filter="currentTable"></table>
</main>
</body>
</html>
```

- [ ] **Step 2: Run the fixture-backed smoke test and verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php --filter test_user_admin_smoke_script_passes_against_fixture_server
```

Expected: FAIL because `expectModuleCenterPage` still requires `模块中心`.

- [ ] **Step 3: Remove the obsolete title assertion**

Keep the authenticated-page and table structure checks:

```php
function expectModuleCenterPage(array $response, string $label): void
{
    expectAdminPageBody($response, $label);
    expectBodyContains($response['body'], 'id="currentTable"', $label);
    expectBodyContains($response['body'], 'lay-filter="currentTable"', $label);
}
```

- [ ] **Step 4: Run focused and adjacent tests and verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php tests\Feature\Modules\ModuleCenterControllerTest.php tests\Feature\User\DeployAcceptanceScriptTest.php
```

Expected: all tests pass with zero failures.

- [ ] **Step 5: Review and commit**

Run:

```powershell
git diff --check
git diff -- scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php
git add scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php docs/superpowers/plans/2026-07-16-module-admin-smoke-production-parity.md
git commit -m "fix: align module admin smoke with production"
```

- [ ] **Step 6: Push, deploy, and verify production**

Push `main`, deploy the two changed runtime files, then run:

```bash
php scripts/user-admin-smoke.php --base-url=https://user.qingyouai.com --admin-prefix=admin --username=admin --password=*** --timeout=20
php artisan system:module-health --json
```

Expected: `OK user admin smoke passed` and module health JSON with `"ok":true`.
