# User Portal Flow Hardening Phase 11 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the user portal session boundary so browser testers get a clear register/login/session/dashboard/logout loop.

**Architecture:** Add a narrow `GET /user/session` endpoint to the existing user auth controller and route group. Update the Blade dashboard to expose that endpoint and update `portal.js` so protected dashboard widgets load only after a successful session check.

**Tech Stack:** PHP 8.3, Laravel 13, Blade, vanilla JavaScript, PHPUnit 12, SQLite test runner.

---

## File Structure

- Modify `app/Http/Controllers/user/AuthController.php`
  - Add `session()` method that returns current `session('user')` or `User login required.`
- Modify `routes/web.php`
  - Add `GET /user/session` inside the existing `/user` API group.
- Modify `resources/views/user/portal/dashboard.blade.php`
  - Add `data-session="/user/session"` and a label target for current user text.
- Modify `public/static/user/js/portal.js`
  - Add session endpoint mapping and dashboard preflight check.
- Create `tests/Feature/User/UserPortalFlowHardeningTest.php`
  - Covers session API and register/login/session/vip/logout flow.
- Modify `tests/Feature/User/UserPortalPageTest.php`
  - Assert dashboard renders the session endpoint hook.

---

## Task 1: Session API And Flow Tests

**Files:**

- Create: `tests/Feature/User/UserPortalFlowHardeningTest.php`
- Modify: `tests/Feature/User/UserPortalPageTest.php`
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing session and flow tests**

Create `tests/Feature/User/UserPortalFlowHardeningTest.php`:

```php
<?php

namespace Tests\Feature\User;

use Tests\TestCase;

class UserPortalFlowHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_session_endpoint_requires_user_login(): void
    {
        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', 'User login required.')
            ->assertJsonPath('data', []);
    }

    public function test_session_endpoint_returns_current_session_user_without_password(): void
    {
        $this->withSession([
            'user' => [
                'id' => 99,
                'email' => 'session@example.com',
                'mobile' => null,
                'nickname' => 'Session User',
            ],
        ])->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('msg', 'User session')
            ->assertJsonPath('data.user.id', 99)
            ->assertJsonPath('data.user.email', 'session@example.com')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_register_login_session_vip_logout_flow_uses_existing_user_apis(): void
    {
        $this->postJson('/user/register', [
            'email' => 'flow@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com');

        $this->postJson('/user/login', [
            'account' => 'flow@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com')
            ->assertSessionHas('user.email', 'flow@example.com');

        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'flow@example.com');

        $this->getJson('/user/vip')
            ->assertOk()
            ->assertJsonPath('code', 1);

        $this->postJson('/user/logout')
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertSessionMissing('user');

        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', 'User login required.');
    }
}
```

In `tests/Feature/User/UserPortalPageTest.php`, add this assertion to `test_dashboard_renders_existing_user_api_endpoint_hooks()` after `data-dashboard-endpoints`:

```php
->assertSee('data-session="/user/session"', false)
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalPageTest.php
```

Expected: FAIL because `/user/session` route and dashboard hook do not exist.

- [ ] **Step 3: Add session endpoint**

In `app/Http/Controllers/user/AuthController.php`, add:

```php
public function session(): JsonResponse
{
    $user = session('user');

    if (empty($user) || ! is_array($user)) {
        return $this->error('User login required.');
    }

    return $this->success('User session', [
        'user' => $user,
    ]);
}
```

In `routes/web.php`, inside the existing `/user` group after logout, add:

```php
Route::get('/session', [\App\Http\Controllers\user\AuthController::class, 'session']);
```

- [ ] **Step 4: Verify partial GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php
```

Expected: PASS for flow tests.

---

## Task 2: Dashboard Session Gate

**Files:**

- Modify: `resources/views/user/portal/dashboard.blade.php`
- Modify: `public/static/user/js/portal.js`
- Test: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Add dashboard session hook**

In `resources/views/user/portal/dashboard.blade.php`, change the current user display to include a label target:

```html
<span data-current-user-label>{{ $currentUser['nickname'] ?? $currentUser['email'] ?? $currentUser['mobile'] ?? 'Not logged in' }}</span>
```

Add the session endpoint to `data-dashboard-endpoints`:

```html
data-session="/user/session"
```

- [ ] **Step 2: Update JS endpoint map**

In `public/static/user/js/portal.js`, add `session` to `endpointMap()`:

```js
session: element.dataset.session,
```

- [ ] **Step 3: Add session preflight helper**

Add this helper before `wireDashboard()`:

```js
async function ensureDashboardSession(endpoints) {
    const status = document.querySelector('[data-dashboard-status]');

    if (!endpoints.session) {
        return true;
    }

    try {
        const result = await request(endpoints.session);
        const ok = Number(result.code) === 1;

        if (!ok) {
            setStatus(status, result.msg || 'User login required.', false);
            return false;
        }

        const user = result.data?.user || {};
        const label = document.querySelector('[data-current-user-label]');
        if (label) {
            label.textContent = user.nickname || user.email || user.mobile || `User #${user.id}`;
        }
        setStatus(status, '', null);

        return true;
    } catch (error) {
        setStatus(status, error.message, false);
        return false;
    }
}
```

- [ ] **Step 4: Gate initial widget loading**

In `wireDashboard()`, replace immediate initial widget loading:

```js
['vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'].forEach((name) => {
    loadBox(name, endpoints[name]);
});
```

with:

```js
ensureDashboardSession(endpoints).then((loggedIn) => {
    if (!loggedIn) {
        return;
    }

    ['vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'].forEach((name) => {
        loadBox(name, endpoints[name]);
    });
});
```

- [ ] **Step 5: Verify GREEN and JS syntax**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalPageTest.php
node --check public\static\user\js\portal.js
```

Expected: all pass.

- [ ] **Step 6: Commit implementation**

Run:

```powershell
git add app/Http/Controllers/user/AuthController.php routes/web.php resources/views/user/portal/dashboard.blade.php public/static/user/js/portal.js tests/Feature/User/UserPortalFlowHardeningTest.php tests/Feature/User/UserPortalPageTest.php
git commit -m "feat: harden user portal session flow"
```

---

## Task 3: Review And Verification

**Files:**

- Review all P11 changed files.

- [ ] **Step 1: Run focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalPageTest.php tests\Feature\User\UserAuthTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\AuthController.php
node --check public\static\user\js\portal.js
git diff --check
```

Expected: clean.

- [ ] **Step 4: Review checklist**

Confirm:

- `/user/session` is in the existing `/user` route group.
- Session response excludes password.
- Logged-out dashboard performs one session check before protected widget fetches.
- Existing `/user/register`, `/user/login`, `/user/logout`, and `/user/vip` behavior remains unchanged.
- No provider or business-rule scope creep was added.

- [ ] **Step 5: Commit review checkpoint**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review user portal flow hardening phase"
```

---

## Plan Self-Review

- Spec coverage: session API, dashboard session gate, flow tests, focused/full verification, and review are covered.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: route, method, test names, view hooks, and JavaScript dataset names match.
- Scope guard: no profile editing, provider integrations, or new business rules are included.
