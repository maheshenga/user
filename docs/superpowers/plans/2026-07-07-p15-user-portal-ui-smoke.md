# P15 User Portal UI Smoke Implementation Plan

> **Execution note:** Implement directly in the current workspace without subagents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure the user portal dashboard UI and JavaScript action wiring are visible, syntactically valid, and covered by deployment smoke.

**Architecture:** Add static and smoke coverage around `/u/dashboard` and `/static/user/js/portal.js`. Use Node `--check` to guard JavaScript syntax and extend the fixture dashboard shell so smoke can verify real user-facing hooks instead of accepting a generic HTML page.

**Tech Stack:** PHP 8.3 smoke script, PHPUnit feature tests, Node `--check` for JavaScript syntax, existing portal Blade/JS files.

## Global Constraints

- Do not change real user account, VIP, balance, invite, withdrawal, or activation business rules.
- Keep smoke checks non-mutating except the existing safe-failure probes from P14.
- Use direct execution in this workspace; do not dispatch subagents.
- Prefer static hook checks over browser automation for this P.

---

## File Structure

- Modify: `scripts/user-portal-smoke.php`
  - Add dashboard page hook assertions and portal JS wiring assertions.
- Modify: `tests/Fixtures/user-portal-smoke-router.php`
  - Return a dashboard fixture containing the real dashboard data hooks and form hooks.
  - Return a portal JS fixture containing the key dashboard action wiring tokens.
- Modify: `tests/Feature/User/UserPortalSmokeScriptTest.php`
  - Assert smoke output includes dashboard UI/JS pass lines.
  - Add a Node syntax-check test for `public/static/user/js/portal.js`.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserPortalSmokeScriptTest.php`

**Interfaces:**
- Consumes: `scripts/user-portal-smoke.php` output and `public/static/user/js/portal.js`.
- Produces: failing checks for dashboard UI smoke coverage and JS syntax validity.

- [ ] **Step 1: Add smoke output assertions**

In both successful smoke tests, add:

```php
$this->assertStringContainsString('PASS GET /u/dashboard dashboard action hooks', $output);
$this->assertStringContainsString('PASS GET /static/user/js/portal.js dashboard action wiring', $output);
```

- [ ] **Step 2: Add JavaScript syntax/static wiring test**

Add this test to `UserPortalSmokeScriptTest`:

```php
public function test_user_portal_dashboard_javascript_is_valid_and_wires_actions(): void
{
    $scriptPath = public_path('static/user/js/portal.js');
    $process = new Process(['node', '--check', $scriptPath], base_path());
    $process->setTimeout(10);
    $process->run();

    $output = $process->getOutput() . $process->getErrorOutput();

    $this->assertSame(0, $process->getExitCode(), $output);

    $script = file_get_contents($scriptPath);
    $this->assertIsString($script);
    $this->assertStringContainsString('data-dashboard-form="activation"', $script);
    $this->assertStringContainsString('data-dashboard-form="withdrawal"', $script);
    $this->assertStringContainsString('endpoints.activation', $script);
    $this->assertStringContainsString('endpoints.withdrawalRequest', $script);
    $this->assertStringContainsString("loadBox('vip'", $script);
    $this->assertStringContainsString("loadBox('withdrawals'", $script);
}
```

- [ ] **Step 3: Run RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserPortalSmokeScriptTest"
```

Expected: FAIL because smoke output lacks UI pass lines. `node --check` should pass and remain as a regression guard.

## Task 2: GREEN Implementation

**Files:**
- Modify: `scripts/user-portal-smoke.php`
- Modify: `tests/Fixtures/user-portal-smoke-router.php`

**Interfaces:**
- Consumes: `SmokeHttpClient::request()`, `expectStatus()`, `expectJsonCode()`.
- Produces:
  - `expectBodyContains(string $body, string $needle, string $label): void`
  - `expectDashboardPage(array $response, string $label): void`
  - `expectPortalScript(array $response, string $label): void`

- [ ] **Step 1: Add smoke helpers**

In `scripts/user-portal-smoke.php`, add:

```php
function expectBodyContains(string $body, string $needle, string $label): void
{
    if (! str_contains($body, $needle)) {
        throw new SmokeFailure("{$label} missing expected content: {$needle}");
    }
}

function expectDashboardPage(array $response, string $label): void
{
    expectStatus($response, [200], $label);
    expectBodyContains($response['body'], 'data-dashboard-endpoints', $label);
    expectBodyContains($response['body'], 'data-activation="/user/activation-code/redeem"', $label);
    expectBodyContains($response['body'], 'data-withdrawal-request="/user/withdrawal/request"', $label);
    expectBodyContains($response['body'], 'data-dashboard-form="activation"', $label);
    expectBodyContains($response['body'], 'data-dashboard-form="withdrawal"', $label);
}

function expectPortalScript(array $response, string $label): void
{
    expectStatus($response, [200], $label);

    foreach ([
        'data-dashboard-form="activation"',
        'data-dashboard-form="withdrawal"',
        'endpoints.activation',
        'endpoints.withdrawalRequest',
        "loadBox('vip'",
        "loadBox('withdrawals'",
    ] as $needle) {
        expectBodyContains($response['body'], $needle, $label);
    }
}
```

- [ ] **Step 2: Call dashboard UI smoke checks**

After the basic page GET loop, add:

```php
$response = $client->request('GET', '/u/dashboard');
expectDashboardPage($response, 'GET /u/dashboard dashboard action hooks');
pass('GET /u/dashboard dashboard action hooks');

$response = $client->request('GET', '/static/user/js/portal.js');
expectPortalScript($response, 'GET /static/user/js/portal.js dashboard action wiring');
pass('GET /static/user/js/portal.js dashboard action wiring');
```

- [ ] **Step 3: Add fixture dashboard and JS responses**

In `tests/Fixtures/user-portal-smoke-router.php`, add a specific `GET /u/dashboard` response before the generic portal page response. Include:

```html
<div data-dashboard-endpoints data-activation="/user/activation-code/redeem" data-withdrawal-request="/user/withdrawal/request"></div>
<form data-dashboard-form="activation"></form>
<form data-dashboard-form="withdrawal"></form>
```

Add `GET /static/user/js/portal.js` fixture response containing:

```javascript
document.querySelector('[data-dashboard-form="activation"]');
document.querySelector('[data-dashboard-form="withdrawal"]');
request(endpoints.activation);
request(endpoints.withdrawalRequest);
loadBox('vip', endpoints.vip);
loadBox('withdrawals', endpoints.withdrawals);
```

- [ ] **Step 4: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserPortalSmokeScriptTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit, Push

**Files:**
- Review all P15 changes.

- [ ] **Step 1: Syntax checks**

Run:

```powershell
node --check public/static/user/js/portal.js
E:\code\user\.tools\php-8.3.32\php.exe -l scripts/user-portal-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Fixtures/user-portal-smoke-router.php
```

- [ ] **Step 2: Full tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Review diff**

Run:

```powershell
git diff --check
git diff --stat
git diff -- scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php docs/superpowers/plans/2026-07-07-p15-user-portal-ui-smoke.md
```

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p15-user-portal-ui-smoke.md scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php
git commit -m "test: smoke user portal dashboard wiring"
git push origin main
```

Expected: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers user portal dashboard visibility, static JS syntax, activation form wiring, withdrawal form wiring, and smoke output.
- Placeholder scan: no placeholders remain.
- Scope check: this P adds smoke/static coverage only; no business rules change.
