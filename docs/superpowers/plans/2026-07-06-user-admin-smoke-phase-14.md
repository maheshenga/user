# User Admin Smoke Phase 14 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a repeatable HTTP smoke check for the EasyAdmin user operations backend.

**Architecture:** Create a standalone PHP smoke script with a cookie-aware HTTP client and a focused fixture server test. The script validates login, menu visibility, dashboard JSON metrics, and representative admin pages without changing business logic or database state.

**Tech Stack:** PHP 8.3, Laravel 13, Symfony Process, PHPUnit 12, PHP built-in server fixture, Composer scripts.

---

## File Structure

- Create `scripts/user-admin-smoke.php`
  - Parses CLI options, performs admin login, validates menu JSON, validates dashboard metrics, and checks key admin pages.
- Create `tests/Fixtures/user-admin-smoke-router.php`
  - Small stateful HTTP fixture for smoke-script tests.
- Create `tests/Feature/User/UserAdminSmokeScriptTest.php`
  - Starts the fixture server and verifies success and failure modes.
- Modify `composer.json`
  - Adds `smoke:user-admin`.

---

## Task 1: Failing Smoke Script Tests

**Files:**

- Create: `tests/Fixtures/user-admin-smoke-router.php`
- Create: `tests/Feature/User/UserAdminSmokeScriptTest.php`

- [ ] **Step 1: Add fixture router**

Create `tests/Fixtures/user-admin-smoke-router.php` with routes for:

- `GET /admin/login`: HTML with `<meta name="csrf-token" content="fixture-admin-token">`.
- `POST /admin/login`: JSON `code=1` when username and password are present.
- `GET /admin/ajax/initAdmin`: JSON menu tree with `User Operations` and `user/dashboard/index`, except in `SMOKE_FIXTURE_MODE=missing-menu`.
- `GET /admin/user/dashboard/index`: JSON metrics when `Accept: application/json`, except in `SMOKE_FIXTURE_MODE=missing-dashboard-metric`.
- `GET /admin/user/dashboard/index`, `/admin/user/account/index`, `/admin/user/withdrawal/index`, `/admin/user/risk-event/index`, `/admin/user/notification-outbox/index`: HTML 200.

- [ ] **Step 2: Add failing PHPUnit tests**

Create `tests/Feature/User/UserAdminSmokeScriptTest.php` with tests:

- `test_user_admin_smoke_script_passes_against_fixture_server`
- `test_user_admin_smoke_script_accepts_space_separated_option_values`
- `test_user_admin_smoke_script_fails_when_user_operations_menu_is_missing`
- `test_user_admin_smoke_script_fails_when_dashboard_metric_is_missing`

Each test starts the fixture server with `PHP_BINARY -S 127.0.0.1:{port} tests/Fixtures/user-admin-smoke-router.php` and runs `scripts/user-admin-smoke.php` as a subprocess.

- [ ] **Step 3: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php
```

Expected: FAIL because `scripts/user-admin-smoke.php` does not exist.

---

## Task 2: Smoke Script Implementation

**Files:**

- Create: `scripts/user-admin-smoke.php`
- Modify: `composer.json`

- [ ] **Step 1: Implement script option parsing**

Support `--base-url`, `--admin-prefix`, `--username`, `--password`, and `--timeout`. Require non-empty `--base-url`, default `admin-prefix=admin`, `username=admin`, `password=123456`, `timeout=10`.

- [ ] **Step 2: Implement HTTP client**

Implement a stream-context client that:

- Stores response cookies.
- Sends stored cookies on later requests.
- Extracts status codes.
- Decodes JSON bodies.
- Sends `X-CSRF-TOKEN` when present.
- Sends `X-Requested-With: XMLHttpRequest` for AJAX login.

- [ ] **Step 3: Implement validation flow**

The script should:

1. Fetch login page and load CSRF token.
2. Submit admin login and require JSON `code=1`.
3. Fetch `ajax/initAdmin` and require menu labels/links.
4. Fetch dashboard JSON and require metric keys:
   - `total_users`
   - `today_registrations`
   - `active_vip_users`
   - `pending_withdrawals`
   - `pending_payouts`
   - `pending_notifications`
   - `retryable_notifications`
   - `risk_events`
   - `today_commission_amount`
5. Fetch representative admin pages and require HTTP 200.

- [ ] **Step 4: Add composer alias**

Add:

```json
"smoke:user-admin": "@php scripts/user-admin-smoke.php"
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-admin-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-admin-smoke-router.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserAdminSmokeScriptTest.php
```

Expected: all pass.

Commit:

```powershell
git add scripts/user-admin-smoke.php tests/Fixtures/user-admin-smoke-router.php tests/Feature/User/UserAdminSmokeScriptTest.php composer.json
git commit -m "feat: add user admin smoke script"
```

---

## Task 3: Review And Verification

**Files:**

- Review all P14 changed files.

- [ ] **Step 1: Run focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php tests\Feature\User\UserOpsVisibilityTest.php
```

Expected: PASS.

- [ ] **Step 2: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\user-admin-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\user-admin-smoke-router.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserAdminSmokeScriptTest.php
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar validate --no-check-publish
git diff --check
```

Expected: all checks clean.

- [ ] **Step 3: Run full SQLite suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 4: Request code review**

Ask a reviewer subagent to inspect the diff from the P14 plan commit through HEAD for plan alignment, code quality, and test strength.

- [ ] **Step 5: Commit review checkpoint**

If the review has no blocking issues:

```powershell
git commit --allow-empty -m "chore: review user admin smoke phase"
```

- [ ] **Step 6: Push**

Run:

```powershell
git push origin main
```

---

## Plan Self-Review

- Spec coverage: script, fixture, tests, composer alias, review, verification, and push are covered.
- Placeholder scan: no TODO or TBD markers remain.
- Type consistency: option names, route paths, metric keys, and test names match the design doc.
- Scope guard: no business services, admin permissions, menu sync service, migrations, or views are changed.
