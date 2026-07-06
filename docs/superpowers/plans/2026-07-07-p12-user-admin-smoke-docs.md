# P12 User Admin Smoke Docs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an operator-facing guide for running the user admin smoke checks during local deployment and QA.

**Architecture:** Create a focused Markdown document under `docs/operations/` and protect it with a feature test that asserts the guide includes the composer command, required options, checked admin surfaces, status UI checks, status endpoint guard checks, and failure triage notes.

**Tech Stack:** Markdown documentation, PHPUnit/Laravel feature test, existing Composer smoke scripts.

## Global Constraints

- Do not change runtime behavior.
- Document the existing `smoke:user-admin` command; do not add a duplicate Composer script.
- Keep commands Windows/PowerShell friendly because the project is operated from `E:\code\user\EasyAdmin8-Laravel`.

---

### Files

- Create: `docs/operations/user-admin-smoke.md`
  - Explain prerequisites, command examples, coverage, expected pass output, and failure triage.
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`
  - Add a documentation contract test for the new guide.

---

### Task 1: RED Documentation Contract

**Files:**
- Modify: `tests/Feature/User/UserAdminSmokeScriptTest.php`

**Interfaces:**
- Consumes: planned Markdown file path `docs/operations/user-admin-smoke.md`.
- Produces: failing test that requires the operator guide to exist and contain key commands/checks.

- [ ] **Step 1: Add failing documentation test**

Add this test after `test_user_admin_smoke_script_passes_against_fixture_server()`:

```php
public function test_user_admin_smoke_operator_guide_documents_command_and_scope(): void
{
    $docPath = base_path('docs/operations/user-admin-smoke.md');
    $this->assertFileExists($docPath);

    $doc = file_get_contents($docPath);
    $this->assertIsString($doc);
    $this->assertStringContainsString('composer run smoke:user-admin --', $doc);
    $this->assertStringContainsString('--base-url=http://127.0.0.1:8000', $doc);
    $this->assertStringContainsString('--admin-prefix=admin', $doc);
    $this->assertStringContainsString('--username=admin', $doc);
    $this->assertStringContainsString('--password=123456', $doc);
    $this->assertStringContainsString('用户运营', $doc);
    $this->assertStringContainsString('账号状态管理', $doc);
    $this->assertStringContainsString('/static/admin/js/user/account.js', $doc);
    $this->assertStringContainsString('/admin/user/account/modify', $doc);
    $this->assertStringContainsString('status endpoint guards', $doc);
    $this->assertStringContainsString('OK user admin smoke passed', $doc);
}
```

- [ ] **Step 2: Run focused test to verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter=test_user_admin_smoke_operator_guide_documents_command_and_scope
```

Expected: FAIL because `docs/operations/user-admin-smoke.md` does not exist.

---

### Task 2: GREEN Documentation

**Files:**
- Create: `docs/operations/user-admin-smoke.md`

**Interfaces:**
- Consumes: `composer run smoke:user-admin --` script alias and `scripts/user-admin-smoke.php` behavior.
- Produces: operator-readable smoke guide.

- [ ] **Step 1: Create guide**

Create `docs/operations/user-admin-smoke.md` with these sections:

```markdown
# User Admin Smoke Test

## Purpose

Use this smoke test after local deployment or a pull from `origin/main` to confirm the admin user-operations surface is visible and wired.

## Prerequisites

- The Laravel app is running, for example at `http://127.0.0.1:8000`.
- The app is installed and has a valid admin account.
- The admin user can access the `用户运营` menu.

## Command

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run smoke:user-admin -- --base-url=http://127.0.0.1:8000 --admin-prefix=admin --username=admin --password=123456 --timeout=10
```

Composer also supports the shorter form when PHP and Composer are already on PATH:

```powershell
composer run smoke:user-admin -- --base-url=http://127.0.0.1:8000 --admin-prefix=admin --username=admin --password=123456
```

## Coverage

- Logs in through `/admin/login` with CSRF.
- Checks `/admin/ajax/initAdmin` includes the `用户运营` menu.
- Requests every user-operations admin page.
- Checks the account page includes `账号状态管理`, status labels, `data-auth-modify`, and `id="userStatusTpl"`.
- Checks `/static/admin/js/user/account.js` includes status buttons, `data-account-status`, `field: 'status'`, `value: status`, and table reload wiring.
- Sends safe rejected probes to `/admin/user/account/modify` for non-status fields and invalid statuses; this is reported as `status endpoint guards` and does not mutate real accounts.

## Expected Result

The final line should be:

```text
OK user admin smoke passed
```

## Failure Triage

- If login fails, verify `--admin-prefix`, `--username`, `--password`, install state, and CSRF/session configuration.
- If the menu check fails, run the menu sync/seed process and confirm `用户运营` is assigned to the admin role.
- If a page looks like a login page, the admin session expired or middleware rejected the request.
- If `账号状态管理` or `/static/admin/js/user/account.js` checks fail, refresh assets and confirm the latest pushed code is deployed.
- If `/admin/user/account/modify` guard checks fail, stop testing account status changes until the backend status-only boundary is restored.
```

- [ ] **Step 2: Run focused tests to verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter=UserAdminSmokeScriptTest
```

Expected: PASS.

---

### Task 3: Verification, Review, Commit, Push

**Files:**
- Review all modified files.

**Interfaces:**
- Consumes: final diff and verification commands.
- Produces: pushed documentation commit on `origin/main`.

- [ ] **Step 1: Run focused and full tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter=UserAdminSmokeScriptTest
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 2: Review diff**

Run:

```powershell
git diff --check
git diff --stat
git diff -- docs/operations/user-admin-smoke.md tests/Feature/User/UserAdminSmokeScriptTest.php docs/superpowers/plans/2026-07-07-p12-user-admin-smoke-docs.md
```

Expected: diff scope is limited to this P12 task plus the plan file.

- [ ] **Step 3: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p12-user-admin-smoke-docs.md docs/operations/user-admin-smoke.md tests/Feature/User/UserAdminSmokeScriptTest.php
git commit -m "docs: document user admin smoke checks"
git push origin main
```

Expected: push succeeds to `origin/main`.

---

### Self-Review

- Spec coverage: P12 documents the command, options, coverage, status UI checks, endpoint guards, expected output, and failure triage.
- Placeholder scan: no placeholders remain.
- Scope check: this plan only adds operator documentation and a documentation contract test.
