# P26 Menu Sync Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Localize admin menu synchronization errors so module center and user operations setup commands do not expose English messages when `system_menu` is missing.

**Architecture:** Keep both menu synchronization services unchanged except for the `RuntimeException` message emitted before any database mutation. Add command-level feature tests first because the error is surfaced through Artisan commands used during deployment and local setup.

**Tech Stack:** Laravel/PHP 8.3, PHPUnit via Composer `test:sqlite`, Artisan command tests.

## Global Constraints

Do not use subagents.
Use CodeGraph before grep or file discovery when locating code.
Use TDD: update tests first, verify RED, then change production code.
Use `apply_patch` for edits.
Keep scope limited to menu sync missing-table errors in `ModuleCenterMenuService` and `UserOpsMenuService`.
Preserve command names, exit codes, menu data, database behavior, and cache refresh behavior.
Commit and push to `origin main` after fresh verification.

---

### Task 1: Add RED command coverage for Chinese missing-table errors

**Files:**
- Modify: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Modify: `tests/Feature/User/UserOpsVisibilityTest.php`

**Interfaces:**
- Consumes: Artisan command `system:module-menu:sync`
- Consumes: Artisan command `user:ops-menu:sync`
- Produces: failed command output containing `系统菜单表不存在，请先完成后台菜单数据表迁移。`

- [ ] **Step 1: Add module menu missing-table test**

Add:

```php
public function test_module_menu_sync_reports_missing_system_menu_table_in_chinese(): void
{
    Schema::dropIfExists('system_menu');

    $this->artisan('system:module-menu:sync')
        ->expectsOutputToContain('系统菜单表不存在，请先完成后台菜单数据表迁移。')
        ->assertExitCode(1);
}
```

- [ ] **Step 2: Add user ops menu missing-table test**

Add:

```php
public function test_user_ops_menu_sync_reports_missing_system_menu_table_in_chinese(): void
{
    Schema::dropIfExists('system_menu');

    $this->artisan('user:ops-menu:sync')
        ->expectsOutputToContain('系统菜单表不存在，请先完成后台菜单数据表迁移。')
        ->assertExitCode(1);
}
```

- [ ] **Step 3: Run focused RED verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleCenterControllerTest|UserOpsVisibilityTest"
```

Expected: FAIL because the services still emit `The system_menu table does not exist.`

### Task 2: Localize menu sync service messages

**Files:**
- Modify: `app/Modules/ModuleCenterMenuService.php`
- Modify: `app/User/UserOpsMenuService.php`

**Interfaces:**
- Consumes: current `sync(): array` missing-table checks
- Produces: same `RuntimeException` type with Chinese message

- [ ] **Step 1: Replace the missing-table message**

Use the same message in both services:

```php
throw new RuntimeException('系统菜单表不存在，请先完成后台菜单数据表迁移。');
```

- [ ] **Step 2: Run focused GREEN verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleCenterControllerTest|UserOpsVisibilityTest"
```

Expected: PASS for the focused suite.

### Task 3: Verify, review, commit, and push

**Files:**
- Verify: `app/Modules/ModuleCenterMenuService.php`
- Verify: `app/User/UserOpsMenuService.php`
- Verify: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Verify: `tests/Feature/User/UserOpsVisibilityTest.php`

- [ ] **Step 1: Syntax check touched PHP files**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleCenterMenuService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/UserOpsMenuService.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleCenterControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserOpsVisibilityTest.php
```

Expected: each command reports `No syntax errors detected`.

- [ ] **Step 2: Run full test suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS with zero failures.

- [ ] **Step 3: Review diff quality**

Run:

```powershell
git diff --check
git diff --stat
git diff -- app/Modules/ModuleCenterMenuService.php app/User/UserOpsMenuService.php tests/Feature/Modules/ModuleCenterControllerTest.php tests/Feature/User/UserOpsVisibilityTest.php docs/superpowers/plans/2026-07-07-p26-menu-sync-errors-chinese.md
```

Expected: no whitespace errors; diff is limited to P26 scope.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add app/Modules/ModuleCenterMenuService.php app/User/UserOpsMenuService.php tests/Feature/Modules/ModuleCenterControllerTest.php tests/Feature/User/UserOpsVisibilityTest.php docs/superpowers/plans/2026-07-07-p26-menu-sync-errors-chinese.md
git commit -m "fix: localize menu sync errors"
git push origin main
```

Expected: commit is created and pushed to `origin/main`.

## Self-Review

Spec coverage: module center menu sync and user operations menu sync missing-table paths are covered.
Placeholder scan: no deferred implementation placeholders remain.
Type consistency: both tests target existing Artisan commands and both services keep `sync(): array`.
