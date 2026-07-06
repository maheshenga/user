# P21 User Auth Validation Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This project run is explicitly inline-only: do not use subagents.

**Goal:** Make user registration, login, password recovery, password reset, and portal page titles return default Chinese copy without relying on Laravel fallback validator text.

**Architecture:** Keep all behavior inside the existing user auth controller and portal controller. Add controller-level validator message arrays so API validation failures are deterministic Chinese, while leaving service rules, routes, database writes, and response envelopes unchanged.

**Tech Stack:** Laravel, PHP 8.3, PHPUnit feature tests, SQLite test database.

## Global Constraints

- No subagents for this execution; implement directly in this session.
- Use TDD: add failing assertions before production code.
- Keep API routes, response shape, auth service behavior, and password reset security behavior unchanged.
- Only touch the P21 files listed below unless verification proves a direct dependency must change.
- Use UTF-8 Chinese copy intentionally; do not rewrite files based on PowerShell mojibake.

---

## File Structure

- Modify: `tests/Feature/User/UserAuthTest.php`
  - Adds endpoint assertions for missing password, missing login account, and missing login password.
- Modify: `tests/Feature/User/UserPasswordResetTest.php`
  - Adds endpoint assertions for missing reset account, short reset password, and missing reset credential.
- Modify: `tests/Feature/User/UserPortalPageTest.php`
  - Adds explicit `<title>` assertions for Chinese portal page titles.
- Modify: `app/Http/Controllers/user/AuthController.php`
  - Supplies Chinese validation messages to existing validator calls.
- Modify: `app/Http/Controllers/user/PortalController.php`
  - Changes portal page titles from English to Chinese.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserAuthTest.php`
- Modify: `tests/Feature/User/UserPasswordResetTest.php`
- Modify: `tests/Feature/User/UserPortalPageTest.php`

**Interfaces:**
- Consumes: existing `/user/register`, `/user/login`, `/user/password/forgot`, `/user/password/reset`, `/u/*` routes.
- Produces: failing expectations for deterministic Chinese validation and page titles.

- [ ] **Step 1: Add user auth endpoint validation assertions**

Add assertions equivalent to:

```php
$this->postJson('/user/register', [
    'mobile' => '13800000012',
])->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '密码不能为空。');

$this->postJson('/user/login', [
    'password' => 'secret123',
])->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '账号不能为空。');

$this->postJson('/user/login', [
    'account' => 'missing@example.com',
])->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '密码不能为空。');
```

- [ ] **Step 2: Add password reset endpoint validation assertions**

Add assertions equivalent to:

```php
$this->postJson('/user/password/forgot', [])
    ->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '账号不能为空。');

$this->postJson('/user/password/reset', [
    'account' => 'missing@example.com',
    'password' => '12345',
])->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '密码至少需要 6 位。');

$this->postJson('/user/password/reset', [
    'account' => 'missing@example.com',
    'password' => 'secret123',
])->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '请填写重置凭证。');
```

- [ ] **Step 3: Add portal title assertions**

Add assertions equivalent to:

```php
$this->get('/u/login')->assertSee('<title>登录 - 用户中心</title>', false);
$this->get('/u/register')->assertSee('<title>注册 - 用户中心</title>', false);
$this->get('/u/forgot-password')->assertSee('<title>找回密码 - 用户中心</title>', false);
$this->get('/u/reset-password')->assertSee('<title>重置密码 - 用户中心</title>', false);
$this->get('/u/dashboard')->assertSee('<title>控制台 - 用户中心</title>', false);
```

- [ ] **Step 4: Run focused RED test**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAuthTest|UserPasswordResetTest|UserPortalPageTest"
```

Expected: FAIL because current controller validation messages and portal titles still include default validator text or English titles.

## Task 2: GREEN Implementation

**Files:**
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `app/Http/Controllers/user/PortalController.php`

**Interfaces:**
- Consumes: existing validator rules and `render(string $view, string $title): View`.
- Produces: deterministic Chinese endpoint validation messages and Chinese portal titles.

- [ ] **Step 1: Add Chinese messages to `register` validator**

Use this exact message map:

```php
[
    'mobile.string' => '手机号格式不正确。',
    'mobile.max' => '手机号不能超过 32 个字符。',
    'email.email' => '邮箱格式不正确。',
    'email.max' => '邮箱不能超过 180 个字符。',
    'password.required' => '密码不能为空。',
    'password.string' => '密码格式不正确。',
    'password.min' => '密码至少需要 6 位。',
    'password.max' => '密码不能超过 72 位。',
    'invite_code.string' => '邀请码格式不正确。',
    'invite_code.max' => '邀请码不能超过 40 个字符。',
]
```

- [ ] **Step 2: Add Chinese messages to `login` validator**

Use this exact message map:

```php
[
    'account.required' => '账号不能为空。',
    'account.string' => '账号格式不正确。',
    'account.max' => '账号不能超过 180 个字符。',
    'password.required' => '密码不能为空。',
    'password.string' => '密码格式不正确。',
    'password.max' => '密码不能超过 72 位。',
]
```

- [ ] **Step 3: Add Chinese messages to password reset validators**

Use `账号不能为空。` for missing forgot/reset account, `密码不能为空。` for missing reset password, `密码至少需要 6 位。` for short password, and format/max messages matching the existing limits.

- [ ] **Step 4: Localize portal titles**

Change only these title arguments:

```php
return $this->render('login', '登录');
return $this->render('register', '注册');
return $this->render('forgot-password', '找回密码');
return $this->render('reset-password', '重置密码');
return $this->render('dashboard', '控制台');
```

- [ ] **Step 5: Run focused GREEN test**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAuthTest|UserPasswordResetTest|UserPortalPageTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit

**Files:**
- Verify all modified files and the full suite.

**Interfaces:**
- Consumes: completed tests and implementation.
- Produces: committed and pushed P21 slice.

- [ ] **Step 1: Run syntax checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/user/AuthController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/user/PortalController.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAuthTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserPasswordResetTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserPortalPageTest.php
```

Expected: no syntax errors.

- [ ] **Step 2: Run full test suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diffs**

```powershell
git diff --check
git diff --stat
git diff -- app/Http/Controllers/user/AuthController.php app/Http/Controllers/user/PortalController.php tests/Feature/User/UserAuthTest.php tests/Feature/User/UserPasswordResetTest.php tests/Feature/User/UserPortalPageTest.php docs/superpowers/plans/2026-07-07-p21-user-auth-validation-chinese.md
```

Expected: no whitespace errors; diff scope matches this plan.

- [ ] **Step 4: Commit and push**

```powershell
git add app/Http/Controllers/user/AuthController.php app/Http/Controllers/user/PortalController.php tests/Feature/User/UserAuthTest.php tests/Feature/User/UserPasswordResetTest.php tests/Feature/User/UserPortalPageTest.php docs/superpowers/plans/2026-07-07-p21-user-auth-validation-chinese.md
git commit -m "fix: localize user auth validation"
git push origin main
```

Expected: commit succeeds and push updates `origin/main`.

## Self-Review

- Spec coverage: covers user register/login/password reset validation, portal visible titles, TDD verification, review, commit, and push.
- Placeholder scan: no `TBD`, `TODO`, `implement later`, or unspecified test steps.
- Type consistency: no new public API; all listed methods and routes already exist.
