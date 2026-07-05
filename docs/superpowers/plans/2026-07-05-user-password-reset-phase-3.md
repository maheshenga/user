# User Password Reset Phase 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add secure ordinary-user password recovery with reset token/code storage, expiry, single-use enforcement, rate-limitable audit records, and user/admin security-log visibility.

**Architecture:** Keep reset orchestration in `App\User\PasswordResetService`. Store only hashes for reset tokens/codes, never plaintext. Treat delivery as out of scope for this phase: the API returns the plaintext reset token only in local/test style response data so a real mail/SMS adapter can replace it later.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin dynamic admin controllers, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `user_password_reset` table for reset requests.
- `user_security_log` table for security/audit events.
- Model classes for reset requests and security logs.
- Request reset by mobile or email.
- Reset password with token or code.
- Token/code hash storage, expiry, used-at, attempt count.
- Reset invalidates current session payload for the reset user.
- User endpoint: `POST /user/password/forgot`.
- User endpoint: `POST /user/password/reset`.
- Admin read-only security-log list.
- Tests for success, expiry, reuse, wrong token/code, unknown account, and endpoint behavior.

Excluded:
- Real SMS or email delivery.
- CAPTCHA.
- Device/session table for revoking all active sessions.
- Admin changing user passwords. That is an admin account-management extension, not P3 reset flow.

---

## File Structure

- Create `database/migrations/2026_07_05_000003_create_user_password_reset_phase_3_tables.php`: creates `user_password_reset` and `user_security_log`.
- Create `app/Models/UserPasswordReset.php`: reset request model.
- Create `app/Models/UserSecurityLog.php`: security/audit log model.
- Create `app/User/PasswordResetService.php`: request and consume reset credentials.
- Create `app/User/UserSecurityLogService.php`: write security events consistently.
- Modify `app/Http/Controllers/user/AuthController.php`: add `forgotPassword()` and `resetPassword()`.
- Modify `routes/web.php`: add `/user/password/forgot` and `/user/password/reset`.
- Create `app/Http/Controllers/admin/user/SecurityLogController.php`: admin read-only security logs.
- Create `resources/views/admin/user/security-log/index.blade.php`: table shell.
- Create `public/static/admin/js/user/security-log.js`: table config.
- Create `tests/Feature/User/UserPasswordResetTest.php`: service and endpoint tests.
- Create `tests/Feature/User/UserAdminSecurityLogControllerTest.php`: admin list tests.

---

## Task 1: Reset and Security Log Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000003_create_user_password_reset_phase_3_tables.php`
- Create: `app/Models/UserPasswordReset.php`
- Create: `app/Models/UserSecurityLog.php`
- Test: `tests/Feature/User/UserPasswordResetTest.php`

- [ ] **Step 1: Write failing persistence test**

Create `UserPasswordResetTest` with setup using `migrate:fresh`, then assert the two tables exist with these columns:

```php
$this->assertTrue(Schema::hasColumns('user_password_reset', [
    'user_id', 'account_type', 'account', 'token_hash', 'code_hash',
    'expires_at', 'used_at', 'request_ip', 'attempt_count', 'create_time',
]));
$this->assertTrue(Schema::hasColumns('user_security_log', [
    'user_id', 'event', 'ip', 'user_agent', 'metadata_json', 'create_time',
]));
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php --filter tables
```

Expected: FAIL because tables/models do not exist.

- [ ] **Step 3: Implement migration and models**

`user_password_reset` stores hashes only. Add indexes on `user_id`, `account`, `expires_at`, and `used_at`.

`user_security_log` stores event rows with JSON metadata. Use `bootSoftDeletes() {}` because audit rows should not be soft-deleted through `delete_time`.

- [ ] **Step 4: Verify GREEN and commit**

Run focused test, then:

```powershell
git add database/migrations/2026_07_05_000003_create_user_password_reset_phase_3_tables.php app/Models/UserPasswordReset.php app/Models/UserSecurityLog.php tests/Feature/User/UserPasswordResetTest.php
git commit -m "feat: add user password reset persistence"
```

## Task 2: Password Reset Service

**Files:**
- Create: `app/User/PasswordResetService.php`
- Create: `app/User/UserSecurityLogService.php`
- Modify: `tests/Feature/User/UserPasswordResetTest.php`

- [ ] **Step 1: Add failing service tests**

Cover:
- request reset by email creates one row with `token_hash` and `code_hash`;
- request reset by mobile normalizes account;
- unknown account returns a generic response and writes no reset row;
- valid token resets password and marks row used;
- valid code resets password and marks row used;
- expired row is rejected;
- used row is rejected;
- wrong token/code increments `attempt_count`;
- reset writes security log event `password_reset_completed`.

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php --filter "reset|password"
```

Expected: FAIL because service classes do not exist.

- [ ] **Step 3: Implement services**

Public API:

```php
public function requestReset(array $payload, string $ip): array;
public function resetPassword(array $payload, string $ip): array;
```

Rules:
- account with `@` is email, otherwise mobile;
- response for unknown account is generic: `['accepted' => true]`;
- token is random 40 chars, code is random 6 digits;
- DB stores `hash('sha256', $token)` and `hash('sha256', $code)`;
- reset accepts either `token` or `code`;
- reset must check `expires_at > now`, `used_at is null`;
- password must be at least 6 chars and max 72 chars;
- after successful reset update `user_account.password`, mark reset `used_at`, clear `session('user')` if it matches the reset user, and write security log.

- [ ] **Step 4: Verify GREEN and commit**

Run focused service tests, then commit:

```powershell
git add app/User/PasswordResetService.php app/User/UserSecurityLogService.php tests/Feature/User/UserPasswordResetTest.php
git commit -m "feat: add user password reset service"
```

## Task 3: User Password Reset Endpoints

**Files:**
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserPasswordResetTest.php`

- [ ] **Step 1: Add failing endpoint tests**

Cover:
- `POST /user/password/forgot` accepts email/mobile and returns `code = 1`;
- response data includes reset token/code for now, so tests can verify flow;
- `POST /user/password/reset` resets password;
- bad payload returns `code = 0`;
- both routes use `CheckInstall` and throttle middleware.

- [ ] **Step 2: Verify RED**

Expected: FAIL with 404 or missing controller methods.

- [ ] **Step 3: Implement controller methods and routes**

Add routes under existing `user` prefix:

```php
Route::post('/password/forgot', [\App\Http\Controllers\user\AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [\App\Http\Controllers\user\AuthController::class, 'resetPassword']);
```

- [ ] **Step 4: Verify GREEN and commit**

Run endpoint tests and `UserAuthTest`, then commit:

```powershell
git add app/Http/Controllers/user/AuthController.php routes/web.php tests/Feature/User/UserPasswordResetTest.php
git commit -m "feat: add user password reset endpoints"
```

## Task 4: Admin Security Log

**Files:**
- Create: `app/Http/Controllers/admin/user/SecurityLogController.php`
- Create: `resources/views/admin/user/security-log/index.blade.php`
- Create: `public/static/admin/js/user/security-log.js`
- Create: `tests/Feature/User/UserAdminSecurityLogControllerTest.php`

- [ ] **Step 1: Add failing admin tests**

Cover:
- `/admin/user/security-log/index` lists safe log fields;
- search/sort allowlist blocks `metadata_json`;
- inherited writes are read-only or forbidden.

- [ ] **Step 2: Verify RED**

Expected: FAIL with missing controller.

- [ ] **Step 3: Implement read-only admin controller and assets**

Use the same allowlist pattern as user account and invite controllers. Expose `id`, `user_id`, `event`, `ip`, `user_agent`, `create_time`; do not expose raw `metadata_json` in list.

- [ ] **Step 4: Verify GREEN and commit**

Run admin tests and:

```powershell
node --check public\static\admin\js\user\security-log.js
git add app/Http/Controllers/admin/user/SecurityLogController.php resources/views/admin/user/security-log/index.blade.php public/static/admin/js/user/security-log.js tests/Feature/User/UserAdminSecurityLogControllerTest.php
git commit -m "feat: add admin user security logs"
```

## Task 5: Review and Full Verification

- [ ] **Step 1: Focused tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSecurityLogControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
```

- [ ] **Step 2: Full suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Lint/static**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\PasswordResetService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserSecurityLogService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\SecurityLogController.php
node --check public\static\admin\js\user\security-log.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- no plaintext token/code is stored;
- reset credentials are single-use;
- expired reset credentials fail;
- wrong attempts increment `attempt_count`;
- successful reset changes password hash;
- successful reset writes security log;
- unknown account response does not reveal account existence;
- no SMS/email provider is hard-coded.

- [ ] **Step 5: Cleanup commit if needed**

```powershell
git add <changed-files>
git commit -m "chore: review user password reset phase 3"
```

## Plan Self-Review

- Spec coverage: This plan covers Phase 3 password reset, token/code storage, expiry, single-use behavior, rate-limitable attempt tracking, and security logs.
- Placeholder scan: No implementation placeholders remain; each task has concrete files, commands, and expected behavior.
- Type consistency: `PasswordResetService`, `UserSecurityLogService`, `UserPasswordReset`, `UserSecurityLog`, routes, and tests use consistent names.
- Scope guard: Delivery providers, VIP, activation codes, commissions, and balances remain deferred to later phases.
