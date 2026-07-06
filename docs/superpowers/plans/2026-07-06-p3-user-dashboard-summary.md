# P3 User Dashboard Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce user dashboard first-load chatter by adding one authenticated dashboard summary API and wiring the portal to hydrate all dashboard panels from that single response.

**Architecture:** Add a thin `DashboardController` that composes existing user services without changing their contracts. Keep the old individual endpoints and refresh buttons for targeted refreshes after activation/withdrawal actions. Frontend first load uses `/user/dashboard/summary`; manual refresh still calls the existing panel endpoints.

**Tech Stack:** Laravel routes/controllers/services, PHPUnit feature tests, Blade, vanilla JavaScript.

---

## File Structure

- Create: `app/Http/Controllers/user/DashboardController.php`
  - Validate session user id.
  - Return one JSON payload with `user`, `vip`, `balance`, `ledger`, `withdrawals`, `invite`, and `inviteRecords`.
- Modify: `routes/web.php`
  - Add `GET /user/dashboard/summary` inside the existing throttled user route group.
- Modify: `resources/views/user/portal/dashboard.blade.php`
  - Add `data-summary="/user/dashboard/summary"` to the endpoint map.
- Modify: `public/static/user/js/portal.js`
  - Add `summary` to `endpointMap`.
  - Add `loadDashboardSummary()` to fetch summary once and render six panels.
  - Fall back to existing individual loads if the summary endpoint is absent or fails.
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`
  - Add API tests for unauthenticated and authenticated summary behavior.
- Modify: `tests/Feature/User/UserPortalPageTest.php`
  - Assert the dashboard page renders the summary endpoint hook.
  - Add static/Node test that first-load JS references `loadDashboardSummary`.

---

## Task 1: Dashboard Summary API

**Files:**
- Modify: `tests/Feature/User/UserPortalFlowHardeningTest.php`
- Create: `app/Http/Controllers/user/DashboardController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing tests**

Add tests:

```php
public function test_dashboard_summary_requires_user_login(): void
{
    $this->getJson('/user/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '请先登录。');
}

public function test_dashboard_summary_returns_all_first_load_panels(): void
{
    $this->withSession(['user' => [
        'id' => 10,
        'email' => 'summary@example.com',
        'mobile' => null,
        'nickname' => 'Summary User',
    ]])->getJson('/user/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('msg', '仪表盘概览')
        ->assertJsonPath('data.user.email', 'summary@example.com')
        ->assertJsonStructure([
            'data' => ['user', 'vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'],
        ]);
}
```

If existing services require a persisted user row, create it with `UserAuthService::register()` and use that id in the session.

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php --filter dashboard_summary
```

Expected: FAIL because the route/controller do not exist.

- [ ] **Step 3: Implement controller and route**

Create `DashboardController` with:

```php
public function summary(
    VipService $vip,
    BalanceLedgerService $balance,
    WithdrawalService $withdrawals,
    InviteService $invites
): JsonResponse
```

Use session `user.id`; if missing return `code=0`, `msg=请先登录。`. Return:

```php
[
    'user' => $sessionUserWithoutPassword,
    'vip' => $vip->summary($userId),
    'balance' => $balance->summary($userId),
    'ledger' => $balance->ledger($userId, 20),
    'withdrawals' => $withdrawals->listForUser($userId, 20),
    'invite' => $invites->inviteSummary($userId),
    'inviteRecords' => $invites->inviteRecords($userId),
]
```

Add route:

```php
Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
```

- [ ] **Step 4: Verify GREEN**

Run focused summary API tests and expect PASS.

---

## Task 2: Portal First-Load Summary Wiring

**Files:**
- Modify: `tests/Feature/User/UserPortalPageTest.php`
- Modify: `resources/views/user/portal/dashboard.blade.php`
- Modify: `public/static/user/js/portal.js`

- [ ] **Step 1: Write failing tests**

Update `test_dashboard_renders_existing_user_api_endpoint_hooks()` to assert:

```php
->assertSee('data-summary="/user/dashboard/summary"', false)
```

Add static assertions:

```php
$script = file_get_contents(public_path('static/user/js/portal.js'));
$this->assertStringContainsString('summary: element.dataset.summary', $script);
$this->assertStringContainsString('loadDashboardSummary(endpoints)', $script);
$this->assertStringContainsString("renderSummaryBox('vip', data.vip)", $script);
```

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php --filter dashboard
```

Expected: FAIL because the summary hook and JS function do not exist.

- [ ] **Step 3: Implement frontend wiring**

Add `data-summary="/user/dashboard/summary"` to the endpoint div.

In `portal.js`:

```js
summary: element.dataset.summary,
```

Add:

```js
function renderSummaryBox(name, data) {
    const box = document.querySelector(`[data-dashboard-box="${name}"]`);
    if (!box) return;
    const rendererName = box.dataset.dashboardRender || name;
    const renderer = renderers[rendererName];
    box.innerHTML = renderer ? renderer(data || {}) : pretty(data || {});
}

async function loadDashboardSummary(endpoints) {
    if (!endpoints.summary) return false;
    const result = await request(endpoints.summary);
    if (Number(result.code) !== 1) return false;
    const data = result.data || {};
    renderSummaryBox('vip', data.vip);
    renderSummaryBox('balance', data.balance);
    renderSummaryBox('ledger', data.ledger);
    renderSummaryBox('withdrawals', data.withdrawals);
    renderSummaryBox('invite', data.invite);
    renderSummaryBox('inviteRecords', data.inviteRecords);
    return true;
}
```

Change first-load flow:

```js
loadDashboardSummary(endpoints).then((loaded) => {
    if (loaded) return;
    // existing six individual loadBox calls
});
```

- [ ] **Step 4: Verify GREEN**

Run focused portal page/dashboard tests and expect PASS.

---

## Task 3: Full Verification and Commit

- [ ] **Step 1: Run focused tests**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalPageTest.php
```

- [ ] **Step 2: Run full SQLite suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Review**

```bash
git diff --check
git diff --stat
git diff
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/plans/2026-07-06-p3-user-dashboard-summary.md app/Http/Controllers/user/DashboardController.php routes/web.php resources/views/user/portal/dashboard.blade.php public/static/user/js/portal.js tests/Feature/User/UserPortalFlowHardeningTest.php tests/Feature/User/UserPortalPageTest.php
git commit -m "feat: add user dashboard summary endpoint"
```

---

## Self-Review

- Spec coverage: Reduces first-load dashboard API calls while preserving old targeted refresh endpoints.
- Placeholder scan: No unresolved placeholders remain.
- Scope check: Does not introduce caching, SEO, or payment work; those remain separate P items.
