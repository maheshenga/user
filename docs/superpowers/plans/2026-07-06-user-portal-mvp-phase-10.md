# User Portal MVP Phase 10 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a minimal browser-visible user portal for the existing user JSON APIs.

**Architecture:** Add page-only routes under `/u`, a thin `PortalController`, Blade views under `resources/views/user/portal`, and one static JavaScript file that calls existing `/user/*` endpoints. Do not change existing business services or API contracts.

**Tech Stack:** PHP 8.3, Laravel 13, Blade, vanilla JavaScript, PHPUnit 12, SQLite test runner.

---

## File Structure

- Create `app/Http/Controllers/user/PortalController.php`
  - Renders user-facing pages and passes the current session user to views.
- Modify `routes/web.php`
  - Adds `/u` routes behind `CheckInstall`.
- Create `resources/views/user/portal/layout.blade.php`
  - Shared user portal shell, CSRF meta tag, and static asset link.
- Create `resources/views/user/portal/login.blade.php`
  - Login form wired to `/user/login`.
- Create `resources/views/user/portal/register.blade.php`
  - Registration form wired to `/user/register`.
- Create `resources/views/user/portal/forgot-password.blade.php`
  - Password-reset request form wired to `/user/password/forgot`.
- Create `resources/views/user/portal/reset-password.blade.php`
  - Password-reset completion form wired to `/user/password/reset`.
- Create `resources/views/user/portal/dashboard.blade.php`
  - Dashboard shell with endpoint hooks for VIP, activation, invite, balance, ledger, withdrawals, and logout.
- Create `public/static/user/js/portal.js`
  - Vanilla JS form submitters and dashboard widget loaders.
- Create `tests/Feature/User/UserPortalPageTest.php`
  - Page rendering and endpoint hook tests.

---

## Task 1: RED Tests For Portal Routes And Views

**Files:**

- Create: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Write failing page tests**

Create `tests/Feature/User/UserPortalPageTest.php`:

```php
<?php

namespace Tests\Feature\User;

use Tests\TestCase;

class UserPortalPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_user_portal_root_redirects_to_dashboard(): void
    {
        $this->get('/u')
            ->assertRedirect('/u/dashboard');
    }

    public function test_login_page_renders_existing_api_endpoint_hook(): void
    {
        $this->get('/u/login')
            ->assertOk()
            ->assertSee('data-portal-form', false)
            ->assertSee('data-endpoint="/user/login"', false)
            ->assertSee('name="account"', false)
            ->assertSee('name="password"', false);
    }

    public function test_register_page_renders_existing_api_endpoint_hook(): void
    {
        $this->get('/u/register')
            ->assertOk()
            ->assertSee('data-endpoint="/user/register"', false)
            ->assertSee('name="mobile"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="invite_code"', false);
    }

    public function test_password_pages_render_existing_api_endpoint_hooks(): void
    {
        $this->get('/u/forgot-password')
            ->assertOk()
            ->assertSee('data-endpoint="/user/password/forgot"', false)
            ->assertSee('name="account"', false);

        $this->get('/u/reset-password')
            ->assertOk()
            ->assertSee('data-endpoint="/user/password/reset"', false)
            ->assertSee('name="account"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="token"', false)
            ->assertSee('name="code"', false);
    }

    public function test_dashboard_renders_existing_user_api_endpoint_hooks(): void
    {
        $this->get('/u/dashboard')
            ->assertOk()
            ->assertSee('data-user-session', false)
            ->assertSee('data-dashboard-endpoints', false)
            ->assertSee('data-vip="/user/vip"', false)
            ->assertSee('data-balance="/user/balance"', false)
            ->assertSee('data-ledger="/user/balance/ledger"', false)
            ->assertSee('data-withdrawals="/user/withdrawal"', false)
            ->assertSee('data-invite="/user/invite"', false)
            ->assertSee('data-invite-records="/user/invite/records"', false)
            ->assertSee('data-activation="/user/activation-code/redeem"', false)
            ->assertSee('data-withdrawal-request="/user/withdrawal/request"', false)
            ->assertSee('data-logout="/user/logout"', false);
    }

    public function test_dashboard_embeds_current_session_user_when_logged_in(): void
    {
        $this->withSession([
            'user' => [
                'id' => 42,
                'email' => 'portal@example.com',
                'mobile' => null,
                'nickname' => 'Portal User',
            ],
        ])->get('/u/dashboard')
            ->assertOk()
            ->assertSee('portal@example.com')
            ->assertSee('&quot;id&quot;:42', false);
    }
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php
```

Expected: FAIL because `/u` routes and views do not exist yet.

---

## Task 2: Portal Controller And Routes

**Files:**

- Create: `app/Http/Controllers/user/PortalController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Add page-only controller**

Create `app/Http/Controllers/user/PortalController.php`:

```php
<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect('/u/dashboard');
    }

    public function login(): View
    {
        return $this->render('login', 'Login');
    }

    public function register(): View
    {
        return $this->render('register', 'Register');
    }

    public function forgotPassword(): View
    {
        return $this->render('forgot-password', 'Forgot Password');
    }

    public function resetPassword(): View
    {
        return $this->render('reset-password', 'Reset Password');
    }

    public function dashboard(): View
    {
        return $this->render('dashboard', 'Dashboard');
    }

    private function render(string $view, string $title): View
    {
        return view('user.portal.' . $view, [
            'title' => $title,
            'currentUser' => session('user', []),
        ]);
    }
}
```

- [ ] **Step 2: Add `/u` routes**

In `routes/web.php`, after the installer routes and before the `/user` API group, add:

```php
Route::middleware([CheckInstall::class])->prefix('u')->group(function (): void {
    Route::get('/', [\App\Http\Controllers\user\PortalController::class, 'index']);
    Route::get('/login', [\App\Http\Controllers\user\PortalController::class, 'login']);
    Route::get('/register', [\App\Http\Controllers\user\PortalController::class, 'register']);
    Route::get('/forgot-password', [\App\Http\Controllers\user\PortalController::class, 'forgotPassword']);
    Route::get('/reset-password', [\App\Http\Controllers\user\PortalController::class, 'resetPassword']);
    Route::get('/dashboard', [\App\Http\Controllers\user\PortalController::class, 'dashboard']);
});
```

- [ ] **Step 3: Verify controller wiring still RED only because views are missing**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php
```

Expected: root redirect may pass; page tests fail because Blade views do not exist.

---

## Task 3: Blade Pages And Static JavaScript

**Files:**

- Create: `resources/views/user/portal/layout.blade.php`
- Create: `resources/views/user/portal/login.blade.php`
- Create: `resources/views/user/portal/register.blade.php`
- Create: `resources/views/user/portal/forgot-password.blade.php`
- Create: `resources/views/user/portal/reset-password.blade.php`
- Create: `resources/views/user/portal/dashboard.blade.php`
- Create: `public/static/user/js/portal.js`
- Test: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Add layout**

Create `resources/views/user/portal/layout.blade.php` with a CSRF token, compact navigation, and `@yield('content')`.

- [ ] **Step 2: Add auth forms**

Create login, register, forgot-password, and reset-password views. Each form must include `data-portal-form`, the correct `data-endpoint`, and matching input `name` attributes from the tests.

- [ ] **Step 3: Add dashboard shell**

Create `dashboard.blade.php` with:

```html
<div data-user-session='@json($currentUser)'></div>
<div data-dashboard-endpoints
     data-vip="/user/vip"
     data-balance="/user/balance"
     data-ledger="/user/balance/ledger"
     data-withdrawals="/user/withdrawal"
     data-invite="/user/invite"
     data-invite-records="/user/invite/records"
     data-activation="/user/activation-code/redeem"
     data-withdrawal-request="/user/withdrawal/request"
     data-logout="/user/logout"></div>
```

Also include visible containers for VIP, balance, ledger, invite, invite records, withdrawal request/list, activation code redemption, and logout.

- [ ] **Step 4: Add static JavaScript**

Create `public/static/user/js/portal.js` that:

- Adds `X-CSRF-TOKEN` to POST requests.
- Submits all `data-portal-form` forms as `FormData`.
- Redirects successful login/register/reset flows to `/u/dashboard` or `/u/login`.
- Loads dashboard widgets by reading the `data-dashboard-endpoints` attributes.
- Shows API `msg` values in status regions.

- [ ] **Step 5: Verify GREEN for portal pages**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php
node --check public\static\user\js\portal.js
```

Expected: portal page tests and JS syntax check pass.

- [ ] **Step 6: Commit portal implementation**

Run:

```powershell
git add app/Http/Controllers/user/PortalController.php routes/web.php resources/views/user/portal public/static/user/js/portal.js tests/Feature/User/UserPortalPageTest.php
git commit -m "feat: add user portal mvp pages"
```

---

## Task 4: Integration Review And Verification

**Files:**

- Review all P10 changed files.

- [ ] **Step 1: Run focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php
```

Expected: all pass.

- [ ] **Step 2: Run full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\PortalController.php
node --check public\static\user\js\portal.js
git diff --check
```

Expected: clean.

- [ ] **Step 4: Review checklist**

Confirm:

- No existing `/user/*` API routes changed.
- Portal controller only renders pages.
- All POST requests use CSRF header.
- Dashboard handles unauthenticated API responses without crashing.
- Tests cover all new visible routes.
- No generated dependency files are committed.

- [ ] **Step 5: Commit review checkpoint**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review user portal mvp phase"
```

---

## Plan Self-Review

- Spec coverage: routes, controller, Blade pages, JS, page tests, focused tests, full suite, static checks, and review are covered.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: route names, file paths, controller methods, view paths, and test assertions match.
- Scope guard: no business service, provider, admin menu, or API contract changes are included.
