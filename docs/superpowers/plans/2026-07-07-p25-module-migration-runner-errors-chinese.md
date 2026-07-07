# P25 Module Migration Runner Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Localize module migration runner errors so install, upgrade, rollback, and manual recovery paths no longer expose English migration messages to administrators.

**Architecture:** Keep `App\Modules\ModuleMigrationRunner` behavior intact and replace only `RuntimeException` message text. Expand existing feature tests first to cover the currently untested migration-shape, irreversible-rollback, cleanup-failure, and missing-recorded-file messages.

**Tech Stack:** Laravel/PHP 8.3, PHPUnit via Composer `test:sqlite`, existing module lifecycle test fixtures.

## Global Constraints

Do not use subagents.
Use CodeGraph before grep or file discovery when locating code.
Use TDD: update tests first, verify RED, then change production code.
Use `apply_patch` for edits.
Keep scope limited to `ModuleMigrationRunner` messages and directly affected tests.
Preserve exception types, database behavior, migration execution order, and rollback behavior.
Commit and push to `origin main` after fresh verification.

---

### Task 1: Add RED coverage for migration runner messages

**Files:**
- Modify: `tests/Feature/Modules/ModulePhase2LifecycleTest.php`
- Modify: `tests/Feature/Modules/ModuleRollbackTest.php`

**Interfaces:**
- Consumes: `App\Modules\ModuleMigrationRunner::runPending()`
- Consumes: `App\Modules\ModuleMigrationRunner::assertReversible()`
- Consumes: `App\Modules\ModuleMigrationRunner::rollbackRecorded()`
- Produces: Chinese `RuntimeException` assertions

- [ ] **Step 1: Update existing missing-file assertions**

Use these messages:

```php
$this->expectExceptionMessage('已记录的模块迁移文件缺失');
$this->assertStringContainsString('已记录的模块迁移文件缺失：2026_07_04_000002_missing_current_file.php', $exception->getMessage());
```

- [ ] **Step 2: Add three focused feature tests**

Add tests that assert:

```php
模块迁移 [2026_07_04_000001_invalid.php] 必须返回包含 up() 方法的对象。
模块迁移 [2026_07_04_000001_cleanup_fails.php] 在原始失败 [up failed] 后执行清理失败：down failed
模块回滚被不可逆迁移阻止：2026_07_04_000001_irreversible.php
```

- [ ] **Step 3: Run focused RED verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModulePhase2LifecycleTest|ModuleRollbackTest"
```

Expected: FAIL because `ModuleMigrationRunner` still emits English messages.

### Task 2: Localize production messages

**Files:**
- Modify: `app/Modules/ModuleMigrationRunner.php`

**Interfaces:**
- Consumes: current migration validation and rollback checks
- Produces: same `RuntimeException` types with Chinese messages

- [ ] **Step 1: Replace the message literals only**

Use these mappings:

```php
"模块迁移 [{$migration}] 必须返回包含 up() 方法的对象。"
"模块迁移 [{$migration}] 在原始失败 [{$exception->getMessage()}] 后执行清理失败：{$cleanupException->getMessage()}"
'模块回滚被不可逆迁移阻止：'.basename($file)
'模块回滚被不可逆迁移阻止：'.$migration
'已记录的模块迁移文件缺失：'.(string) $records->first()->migration
'已记录的模块迁移文件缺失：'.$record->migration
'已记录的模块迁移文件缺失：'.$migration
```

- [ ] **Step 2: Run focused GREEN verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModulePhase2LifecycleTest|ModuleRollbackTest"
```

Expected: PASS for the focused suite.

### Task 3: Verify, review, commit, and push

**Files:**
- Verify: `app/Modules/ModuleMigrationRunner.php`
- Verify: `tests/Feature/Modules/ModulePhase2LifecycleTest.php`
- Verify: `tests/Feature/Modules/ModuleRollbackTest.php`

- [ ] **Step 1: Syntax check touched PHP files**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleMigrationRunner.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModulePhase2LifecycleTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleRollbackTest.php
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
git diff -- app/Modules/ModuleMigrationRunner.php tests/Feature/Modules/ModulePhase2LifecycleTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p25-module-migration-runner-errors-chinese.md
```

Expected: no whitespace errors; diff is limited to P25 scope.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add app/Modules/ModuleMigrationRunner.php tests/Feature/Modules/ModulePhase2LifecycleTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p25-module-migration-runner-errors-chinese.md
git commit -m "fix: localize module migration errors"
git push origin main
```

Expected: commit is created and pushed to `origin/main`.

## Self-Review

Spec coverage: migration shape, cleanup failure, irreversible rollback, and missing recorded migration file messages are covered.
Placeholder scan: no deferred implementation placeholders remain.
Type consistency: all steps use existing `ModuleMigrationRunner` public methods and `RuntimeException`.
