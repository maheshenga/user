# P5 User Session Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure user API requests do not trust stale session data after a user account is disabled, frozen, or deleted.

**Architecture:** Add a small shared `UserApiController` base class for user-facing JSON endpoints. It resolves the current session user against the `user_account` table on every protected request, refreshes the session with public user fields when valid, and clears the session when invalid.

**Tech Stack:** Laravel controllers, Eloquent `UserAccount`, existing `UserAccountStatus`, PHPUnit feature tests, SQLite test runner.

---

## File Structure

- Create: `app/Http/Controllers/user/UserApiController.php`
  - Provides `currentUser()`, `currentUserId()`, `jsonSuccess()`, and `jsonError()` helpers.
  - Verifies `session('user.id')` against the database and `UserAccountStatus::canLogin()`.
- Modify: `app/Http/Controllers/user/AuthController.php`
  - Extends `UserApiController`.
  - Uses `currentUser()` in `/user/session`.
- Modify: `app/Http/Controllers/user/DashboardController.php`
  - Extends `UserApiController`.
  - Uses `currentUser()` for `/user/dashboard/summary`.
- Modify: `app/Http/Controllers/user/VipController.php`
  - Extends `UserApiController` and removes duplicated private JSON/session helpers.
- Modify: `app/Http/Controllers/user/BalanceController.php`
  - Extends `UserApiController` and removes duplicated private JSON/session helpers.
- Modify: `app/Http/Controllers/user/InviteController.php`
  - Extends `UserApiController` and removes duplicated private JSON/session helpers.
- Modify: `app/Http/Controllers/user/WithdrawalController.php`
  - Extends `UserApiController` and removes duplicated private JSON/session helpers.
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`
  - Adds stale-session regression tests for disabled/deleted users.

---

## Task 1: RED Tests For Stale User Sessions

**Files:**
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`

- [ ] **Step 1: Add imports**

Add:

```php
use App\Models\UserAccount;
use App\User\UserAccountStatus;
```

- [ ] **Step 2: Add disabled user stale-session test**

Add:

```php
public function test_user_session_endpoint_rejects_disabled_stale_session_user(): void
{
    $registered = app(UserAuthService::class)->register([
        'email' => 'disabled-session@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    UserAccount::query()
        ->whereKey($registered['user']['id'])
        ->update(['status' => UserAccountStatus::DISABLED]);

    $this->withSession(['user' => $registered['user']])
        ->getJson('/user/session')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '请先登录。')
        ->assertSessionMissing('user');
}
```

- [ ] **Step 3: Add protected endpoint coverage for stale sessions**

Add:

```php
public function test_user_protected_endpoints_reject_deleted_stale_session_user(): void
{
    $registered = app(UserAuthService::class)->register([
        'email' => 'deleted-session@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    UserAccount::query()->whereKey($registered['user']['id'])->delete();

    foreach ([
        '/user/dashboard/summary',
        '/user/vip',
        '/user/balance',
        '/user/balance/ledger',
        '/user/withdrawal',
        '/user/invite',
        '/user/invite/records',
    ] as $endpoint) {
        $this->withSession(['user' => $registered['user']])
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请先登录。')
            ->assertSessionMissing('user');
    }
}
```

- [ ] **Step 4: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php --filter "stale_session|disabled_stale|deleted_stale"
```

Expected: FAIL because existing controllers trust `session('user.id')`.

---

## Task 2: Shared User API Guard

**Files:**
- Create: `app/Http/Controllers/user/UserApiController.php`

- [ ] **Step 1: Create base controller**

Create:

```php
<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
use App\User\UserAccountStatus;
use Illuminate\Http\JsonResponse;

abstract class UserApiController extends Controller
{
    protected function currentUser(): ?array
    {
        $sessionUser = session('user');

        if (! is_array($sessionUser) || empty($sessionUser['id'])) {
            return null;
        }

        $account = UserAccount::query()->find((int) $sessionUser['id']);

        if ($account === null || ! UserAccountStatus::canLogin((string) $account->status)) {
            session()->forget('user');

            return null;
        }

        $user = [
            'id' => $account->id,
            'mobile' => $account->mobile,
            'email' => $account->email,
            'nickname' => $account->nickname,
            'status' => $account->status,
        ];

        session(['user' => $user]);

        return $user;
    }

    protected function currentUserId(): ?int
    {
        $user = $this->currentUser();

        return $user === null ? null : (int) $user['id'];
    }

    protected function jsonSuccess(string $message, array $data = []): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => $message,
            'data' => $data,
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    protected function jsonError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => $message,
            'data' => [],
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }
}
```

- [ ] **Step 2: Syntax check**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\UserApiController.php
```

Expected: no syntax errors.

---

## Task 3: Wire User Controllers To The Guard

**Files:**
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `app/Http/Controllers/user/DashboardController.php`
- Modify: `app/Http/Controllers/user/VipController.php`
- Modify: `app/Http/Controllers/user/BalanceController.php`
- Modify: `app/Http/Controllers/user/InviteController.php`
- Modify: `app/Http/Controllers/user/WithdrawalController.php`

- [ ] **Step 1: Update class inheritance**

For each protected user API controller, replace:

```php
use App\Http\Controllers\Controller;
```

and:

```php
class ExampleController extends Controller
```

with:

```php
class ExampleController extends UserApiController
```

Because the controllers live in the same namespace, no import is required.

- [ ] **Step 2: Update `/user/session`**

In `AuthController::session()`, replace direct session reading with:

```php
$user = $this->currentUser();

if ($user === null) {
    return $this->jsonError('请先登录。');
}

return $this->jsonSuccess('用户会话', [
    'user' => $user,
]);
```

- [ ] **Step 3: Update dashboard summary**

In `DashboardController::summary()`, replace direct `session('user')` access with:

```php
$user = $this->currentUser();
if ($user === null) {
    return $this->jsonError('请先登录。');
}

$userId = (int) $user['id'];
```

- [ ] **Step 4: Remove duplicated helpers**

Remove private `currentUserId()`, `jsonSuccess()`, and `jsonError()` methods from `VipController`, `BalanceController`, `InviteController`, `WithdrawalController`, and `DashboardController`.

- [ ] **Step 5: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php --filter "stale_session|disabled_stale|deleted_stale"
```

Expected: PASS.

---

## Task 4: Full Verification, Review, Commit, Push

**Files:**
- Review all changed P5 files.

- [ ] **Step 1: Run focused user tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserAuthTest.php tests\Feature\User\UserPortalPageTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full SQLite suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run syntax and diff checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\UserApiController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\AuthController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\DashboardController.php
git diff --check
git diff --stat
git diff
```

Expected: clean review with no unrelated changes.

- [ ] **Step 4: Commit and push**

```powershell
git add docs/superpowers/plans/2026-07-06-p5-user-session-guard.md app/Http/Controllers/user/UserApiController.php app/Http/Controllers/user/AuthController.php app/Http/Controllers/user/DashboardController.php app/Http/Controllers/user/VipController.php app/Http/Controllers/user/BalanceController.php app/Http/Controllers/user/InviteController.php app/Http/Controllers/user/WithdrawalController.php tests/Feature/User/UserPortalFlowHardeningTest.php
git commit -m "fix: guard stale user sessions"
git push origin main
```

---

## Self-Review

- Spec coverage: Handles disabled, frozen, deleted, and missing session users for protected user APIs.
- Placeholder scan: No TODO, TBD, or incomplete sections.
- Type consistency: `currentUser()` returns the same public field set as `UserAuthService::publicUser()`.
- Scope guard: No admin permissions, migrations, password flow rules, or frontend behavior are changed.
