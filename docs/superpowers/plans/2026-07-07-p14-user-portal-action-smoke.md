# P14 User Portal Action Smoke Implementation Plan

> **Execution note:** Implement directly in the current workspace without subagents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the user portal smoke check so deployment QA proves activation-code redemption and withdrawal request endpoints are wired after login.

**Architecture:** Reuse `scripts/user-portal-smoke.php` and the existing fixture server. Add low-risk POST probes after the authenticated read-only checks. The smoke intentionally sends invalid/empty action payloads and expects controlled `code=0` business responses, proving request wiring, CSRF/session continuity, and controller reachability without redeeming real activation codes or creating real withdrawals.

**Tech Stack:** Plain PHP smoke script, Symfony Process fixture tests, Laravel/PHPUnit SQLite test runner.

## Global Constraints

- Keep the production smoke action probes deterministic and safe.
- Do not create real payouts or depend on real activation-code inventory in the fixture tests.
- Preserve existing `--base-url`, `--email`, `--password`, and `--timeout` CLI behavior.
- Execute directly in this session; do not dispatch subagents.

---

## File Structure

- Modify: `scripts/user-portal-smoke.php`
  - Add authenticated POST checks for `/user/activation-code/redeem` and `/user/withdrawal/request`.
- Modify: `tests/Fixtures/user-portal-smoke-router.php`
  - Add fixture responses for activation redemption and withdrawal request.
- Modify: `tests/Feature/User/UserPortalSmokeScriptTest.php`
  - Assert successful smoke output includes both action pass lines.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserPortalSmokeScriptTest.php`

**Interfaces:**
- Consumes: existing `scripts/user-portal-smoke.php` output.
- Produces: failing expectations for user action smoke pass lines.

- [ ] **Step 1: Add success output assertions**

In `test_user_portal_smoke_script_passes_against_fixture_server()`, add:

```php
$this->assertStringContainsString('PASS POST /user/activation-code/redeem', $output);
$this->assertStringContainsString('PASS POST /user/withdrawal/request', $output);
```

In `test_user_portal_smoke_script_accepts_space_separated_option_values()`, add the same two assertions so both CLI option styles cover the action probes.

- [ ] **Step 2: Run RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserPortalSmokeScriptTest"
```

Expected: FAIL because the smoke script does not yet print the two action pass lines.

## Task 2: GREEN Implementation

**Files:**
- Modify: `scripts/user-portal-smoke.php`
- Modify: `tests/Fixtures/user-portal-smoke-router.php`

**Interfaces:**
- Consumes: `SmokeHttpClient::request()`, `expectJsonCode()`, existing logged-in smoke session.
- Produces: two authenticated safe-failure action probes.

- [ ] **Step 1: Add fixture action responses**

In `tests/Fixtures/user-portal-smoke-router.php`, after the authenticated GET endpoint block, add:

```php
if ($method === 'POST' && $path === '/user/activation-code/redeem') {
    $payload = $input();

    if (! $state['logged_in']) {
        $json(['code' => 0, 'msg' => 'not logged in']);
        return;
    }

    $json(['code' => 0, 'msg' => 'Activation code is required.']);
    return;
}

if ($method === 'POST' && $path === '/user/withdrawal/request') {
    $payload = $input();

    if (! $state['logged_in']) {
        $json(['code' => 0, 'msg' => 'not logged in']);
        return;
    }

    $json(['code' => 0, 'msg' => 'Withdrawal amount must be positive.']);
    return;
}
```

- [ ] **Step 2: Add smoke action probes**

In `scripts/user-portal-smoke.php`, after the authenticated GET endpoint loop and before logout, add:

```php
$response = $client->request('POST', '/user/activation-code/redeem', [
    'code' => '',
]);
expectJsonCode($response, 0, 'POST /user/activation-code/redeem');
pass('POST /user/activation-code/redeem');

$response = $client->request('POST', '/user/withdrawal/request', [
    'amount' => '0',
]);
expectJsonCode($response, 0, 'POST /user/withdrawal/request');
pass('POST /user/withdrawal/request');
```

- [ ] **Step 3: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserPortalSmokeScriptTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit, Push

**Files:**
- Review all P14 changes.

**Interfaces:**
- Consumes: final diff and verification commands.
- Produces: pushed commit on `origin/main`.

- [ ] **Step 1: PHP syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l scripts/user-portal-smoke.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Fixtures/user-portal-smoke-router.php
```

Expected: no syntax errors.

- [ ] **Step 2: Full test suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diff**

Run:

```powershell
git diff --check
git diff --stat
git diff -- scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php docs/superpowers/plans/2026-07-07-p14-user-portal-action-smoke.md
```

Expected: diff scope is limited to P14 plan and user portal smoke coverage.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p14-user-portal-action-smoke.md scripts/user-portal-smoke.php tests/Fixtures/user-portal-smoke-router.php tests/Feature/User/UserPortalSmokeScriptTest.php
git commit -m "test: smoke user portal actions"
git push origin main
```

Expected: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers the two missing user-facing safe-failure action probes: activation-code redemption and withdrawal request.
- Placeholder scan: no placeholders remain.
- Scope check: this phase only extends deployment smoke coverage and fixture parity; it does not alter or execute successful real activation, balance, or payout business logic.
