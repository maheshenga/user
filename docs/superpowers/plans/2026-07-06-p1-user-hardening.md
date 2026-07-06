# P1 User Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the first high-priority hardening bundle from the SaaS audit: Chinese user API messages, login/reset abuse controls, duplicate-submit protection, and stale admin-session safety.

**Architecture:** Keep the existing Laravel service/controller shape. Add small safeguards in existing services, keep route contracts unchanged, and cover each change with focused PHPUnit or static regression tests before implementation.

**Tech Stack:** Laravel, PHPUnit, SQLite test database, Blade, vanilla JavaScript.

---

## File Structure

- Modify: `app/User/UserAuthService.php`
  - Localize user-visible registration/login errors.
  - Add account + login type + IP failure lockout for 15 minutes after 5 failures.
- Modify: `app/User/PasswordResetService.php`
  - Localize user-visible reset errors.
  - Reject reset rows after 5 wrong token/code attempts.
- Modify: `app/Http/Controllers/user/*.php`
  - Localize unauthenticated and success messages returned by user APIs.
- Modify: `app/Http/Services/AuthService.php`
  - Return empty auth data for stale admin ids instead of throwing a `get_object_vars(null)` TypeError.
- Modify: `public/static/user/js/portal.js`
  - Disable form submit controls while requests are pending.
- Modify: `tests/Feature/User/UserAuthTest.php`
  - Add Chinese API message and login lockout tests.
  - Update existing assertions to the localized contract.
- Modify: `tests/Feature/User/UserPasswordResetTest.php`
  - Add reset attempt-limit test and update localized reset assertions.
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`
  - Add static duplicate-submit guard test.
- Modify: `tests/Feature/User/UserInviteTest.php`
  - Update unauthenticated message assertion.
- Modify: `tests/Fixtures/user-portal-smoke-router.php`
  - Keep smoke fixture messages aligned with the localized login contract.
- Create: `tests/Feature/Admin/AdminAuthServiceTest.php`
  - Cover stale admin id behavior.

---

## Task 1: User API Chinese Messages

**Files:**
- Modify: `tests/Feature/User/UserAuthTest.php`
- Modify: `app/User/UserAuthService.php`
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `app/Http/Controllers/user/VipController.php`
- Modify: `app/Http/Controllers/user/BalanceController.php`
- Modify: `app/Http/Controllers/user/InviteController.php`
- Modify: `app/Http/Controllers/user/WithdrawalController.php`
- Modify: `app/Http/Controllers/user/ActivationCodeController.php`

- [ ] **Step 1: Write failing tests**

Add `test_user_api_messages_are_chinese()` to assert:

```php
$this->getJson('/user/session')->assertJsonPath('msg', '请先登录。');
$this->getJson('/user/vip')->assertJsonPath('msg', '请先登录。');
$this->postJson('/user/register', ['password' => 'secret123'])
    ->assertJsonPath('msg', '请填写手机号或邮箱。');
```

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php --filter user_api_messages_are_chinese
```

Expected: FAIL because current responses still include English messages.

- [ ] **Step 3: Implement localization**

Update user-visible messages:

```php
'Mobile or email is required.' => '请填写手机号或邮箱。'
'Password must be at least 6 characters.' => '密码至少需要 6 位。'
'Mobile already exists.' => '手机号已存在。'
'Email already exists.' => '邮箱已存在。'
'Account and password are required.' => '请填写账号和密码。'
'Invalid account or password.' => '账号或密码错误。'
'User account is not active.' => '账号当前不可登录。'
'User login required.' => '请先登录。'
'User session' => '用户会话'
```

Controller success messages use Chinese labels such as `VIP 概览`, `余额概览`, `余额流水`, `邀请概览`, `邀请记录`, `提现申请已提交`, `提现记录`, and `激活码兑换成功。`.

- [ ] **Step 4: Verify GREEN**

Run the same focused test and expect PASS.

---

## Task 2: Login Failure Lockout

**Files:**
- Modify: `tests/Feature/User/UserAuthTest.php`
- Modify: `app/User/UserAuthService.php`

- [ ] **Step 1: Write failing test**

Add `test_login_locks_account_after_repeated_failures()`:

```php
for ($i = 0; $i < 5; $i++) {
    try {
        $service->login([
            'account' => 'lock@example.com',
            'password' => 'wrong-password',
        ], '127.0.0.9');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('账号或密码错误。', $exception->getMessage());
    }
}

$this->expectExceptionMessage('登录失败次数过多，请 15 分钟后再试。');
$service->login([
    'account' => 'lock@example.com',
    'password' => 'secret123',
], '127.0.0.9');
```

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php --filter login_locks_account_after_repeated_failures
```

Expected: FAIL because repeated failed attempts do not yet block login.

- [ ] **Step 3: Implement lockout**

Add `ensureLoginNotLocked()` before password verification. Count failed rows in `user_login_log` by `account`, `login_type`, `ip`, `result = failed`, and `create_time >= time() - 900`. Throw `登录失败次数过多，请 15 分钟后再试。` at 5 or more failures.

- [ ] **Step 4: Verify GREEN**

Run the focused test and expect PASS.

---

## Task 3: Password Reset Attempt Limit

**Files:**
- Modify: `tests/Feature/User/UserPasswordResetTest.php`
- Modify: `app/User/PasswordResetService.php`

- [ ] **Step 1: Write failing test**

Add `test_reset_token_is_blocked_after_too_many_wrong_attempts()`:

```php
for ($i = 0; $i < 5; $i++) {
    try {
        $service->resetPassword([
            'account' => 'reset-limit@example.com',
            'token' => 'bad-secret',
            'password' => 'new-secret123',
        ], '127.0.0.1');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('重置凭证无效。', $exception->getMessage());
    }
}

$this->expectExceptionMessage('重置尝试次数过多，请重新申请。');
$service->resetPassword([
    'account' => 'reset-limit@example.com',
    'token' => $outbox->payload_json['token'],
    'password' => 'new-secret123',
], '127.0.0.1');
```

- [ ] **Step 2: Verify RED**

Expected: FAIL because the valid token still works after five bad attempts.

- [ ] **Step 3: Implement attempt guard**

Reject the latest reset row when `attempt_count >= 5`. Increment `attempt_count` on wrong token/code. Localize reset messages:

```php
'请填写账号。'
'请填写重置凭证。'
'密码长度需要在 6 到 72 位之间。'
'重置凭证无效。'
'重置凭证已使用。'
'重置凭证已过期。'
'重置尝试次数过多，请重新申请。'
```

- [ ] **Step 4: Verify GREEN**

Run the focused test and expect PASS.

---

## Task 4: Portal Duplicate Submit Guard

**Files:**
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`
- Modify: `public/static/user/js/portal.js`

- [ ] **Step 1: Write failing static test**

Assert `portal.js` contains:

```php
'setFormBusy(form, true)'
'setFormBusy(form, false)'
'setFormBusy(activationForm, true)'
'setFormBusy(withdrawalForm, true)'
```

- [ ] **Step 2: Verify RED**

Expected: FAIL because `setFormBusy` does not exist.

- [ ] **Step 3: Implement duplicate-submit guard**

Add:

```js
function setFormBusy(form, busy) {
    form.querySelectorAll('button, input[type="submit"]').forEach((element) => {
        element.disabled = busy;
    });
}
```

Call it before each async submit request and restore it in `finally`.

- [ ] **Step 4: Verify GREEN**

Run the focused test and expect PASS.

---

## Task 5: Stale Admin Session Safety

**Files:**
- Create: `tests/Feature/Admin/AdminAuthServiceTest.php`
- Modify: `app/Http/Services/AuthService.php`

- [ ] **Step 1: Write failing test**

Assert a missing admin id returns empty auth data and `checkNode()` returns false instead of throwing:

```php
$service = new AuthService(999999);
$this->assertSame([], $service->getAdminInfo());
$this->assertSame([], $service->getAdminNode());
$this->assertFalse($service->checkNode('admin.index/index'));
```

- [ ] **Step 2: Verify RED**

Expected: ERROR with `get_object_vars(): Argument #1 ($object) must be of type object, null given`.

- [ ] **Step 3: Implement null guards**

Return `[]` from `getAdminInfo()` and `getAdminNode()` when the queried admin row is missing.

- [ ] **Step 4: Verify GREEN**

Run the focused test and expect PASS.

---

## Task 6: Verification, Review, Commit

- [ ] **Step 1: Run focused tests**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php tests\Feature\User\UserPasswordResetTest.php tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserInviteTest.php tests\Feature\Admin\AdminAuthServiceTest.php
```

- [ ] **Step 2: Run smoke script tests**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalSmokeScriptTest.php tests\Feature\User\DeployAcceptanceScriptTest.php
```

- [ ] **Step 3: Run full SQLite suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 4: Review diff**

```bash
git diff --check
git diff --stat
git diff
```

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/plans/2026-07-06-p1-user-hardening.md app/User/UserAuthService.php app/User/PasswordResetService.php app/Http/Controllers/user app/Http/Services/AuthService.php public/static/user/js/portal.js tests/Feature/User tests/Feature/Admin tests/Fixtures/user-portal-smoke-router.php
git commit -m "fix: harden user auth and portal flows"
```

---

## Self-Review

- Spec coverage: Covers localized user API messages, login failure lockout, password reset attempt limit, duplicate-submit prevention, and stale admin-session safety.
- Placeholder scan: No `TBD`, unresolved placeholders, or vague implementation instructions remain.
- Type consistency: All referenced classes and paths exist in the current Laravel project.
