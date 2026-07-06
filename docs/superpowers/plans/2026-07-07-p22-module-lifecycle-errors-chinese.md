# P22 Module Lifecycle Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This project run is explicitly inline-only: do not use subagents.

**Goal:** Localize module container lifecycle, admin review, upgrade, and rollback errors so module operators do not see English service exceptions in the backend or CLI flows.

**Architecture:** Keep the module state machine, routes, response envelope, logs, and database schema unchanged. Replace only human-facing exception messages in the module lifecycle services, then update existing module feature tests to prove the new Chinese messages are returned and persisted.

**Tech Stack:** Laravel, PHP 8.3, PHPUnit feature tests, SQLite test database.

## Global Constraints

- No subagents for this execution; implement directly in this session.
- Use TDD: update/add failing assertions before production code.
- Do not change module status enum values such as `pending_review`, `enabled`, `disabled`, or `installed`.
- Do not change module lifecycle behavior, routes, menu sync, migration execution, zip extraction semantics, or rollback safety rules.
- Only touch the P22 files listed below unless RED/GREEN verification proves a direct dependency must change.

---

## File Structure

- Modify: `tests/Feature/Modules/ModuleLifecycleTest.php`
  - Updates install and CLI enable invalid-state assertions to Chinese.
- Modify: `tests/Feature/Modules/ModuleCenterControllerTest.php`
  - Updates admin controller lifecycle failure assertion to Chinese.
- Modify: `tests/Feature/Modules/ModuleUpgradeTest.php`
  - Updates upgrade version, manifest mismatch, and lock assertions to Chinese.
- Modify: `tests/Feature/Modules/ModuleRollbackTest.php`
  - Updates rollback invalid state, missing backup, manifest mismatch, manual rollback, and lock assertions to Chinese.
- Modify: `app/Modules/ModuleInstaller.php`
  - Localizes install/enable/disable/uninstall exception messages.
- Modify: `app/Modules/ModuleRepository.php`
  - Localizes approve/reject invalid-state messages.
- Modify: `app/Modules/ModuleUpgrader.php`
  - Localizes upgrade, version, manifest mismatch, target, and lock messages.
- Modify: `app/Modules/ModuleRollbacker.php`
  - Localizes rollback invalid-state, missing backup, manifest mismatch, manual rollback, replacement, and lock messages.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/Modules/ModuleLifecycleTest.php`
- Modify: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Modify: `tests/Feature/Modules/ModuleUpgradeTest.php`
- Modify: `tests/Feature/Modules/ModuleRollbackTest.php`

**Interfaces:**
- Consumes: existing module service exception messages and admin controller `msg` response.
- Produces: failing expectations for Chinese module lifecycle messages.

- [ ] **Step 1: Update install and CLI lifecycle expectations**

Change expectations to:

```php
$this->expectExceptionMessage('模块 [blog] 必须先通过审核才能安装。');

$this->artisan('module:enable', ['name' => 'blog'])
    ->expectsOutputToContain('模块 [blog] 当前状态 [pending_review] 不允许启用。')
    ->assertExitCode(1);
```

- [ ] **Step 2: Update admin controller lifecycle failure expectation**

Change the missing module failure to:

```php
$response->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '模块未安装：missing');
```

- [ ] **Step 3: Update upgrade error expectations**

Change expectations to:

```php
$this->assertStringContainsString('新版本 [1.0.0] 必须大于当前版本 [1.0.0]', $exception->getMessage());
$this->assertStringContainsString('期望模块 [blog]，实际为 [shop]。', $exception->getMessage());
$this->assertStringContainsString('模块 [blog] 正在升级中，请稍后再试。', $exception->getMessage());
$this->assertStringContainsString('期望模块 [shop]，实际为 [blog]。', $exception->getMessage());
```

- [ ] **Step 4: Update rollback error expectations**

Change expectations to:

```php
$this->assertStringContainsString("模块 [blog] 当前状态 [{$status}] 不允许回滚。", $exception->getMessage());
$this->assertStringContainsString('未找到模块备份：blog', $exception->getMessage());
'last_error' => '未找到模块备份：blog',
$this->assertStringContainsString('期望模块 [blog]，实际为 [shop]。', $exception->getMessage());
$this->assertStringContainsString('需要人工回滚', $exception->getMessage());
$this->assertStringContainsString('模块 [blog] 正在升级中，请稍后再试。', $exception->getMessage());
```

- [ ] **Step 5: Run focused RED tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleLifecycleTest|ModuleCenterControllerTest|ModuleUpgradeTest|ModuleRollbackTest"
```

Expected: FAIL because current module services still return English exception messages.

## Task 2: GREEN Implementation

**Files:**
- Modify: `app/Modules/ModuleInstaller.php`
- Modify: `app/Modules/ModuleRepository.php`
- Modify: `app/Modules/ModuleUpgrader.php`
- Modify: `app/Modules/ModuleRollbacker.php`

**Interfaces:**
- Consumes: existing exceptions thrown by module services.
- Produces: the same exception types with Chinese messages.

- [ ] **Step 1: Localize `ModuleInstaller` messages**

Use these exact replacements:

```php
"Module not found: {$name}" => "模块不存在：{$name}"
"Module [{$name}] must be approved before install." => "模块 [{$name}] 必须先通过审核才能安装。"
"Module not installed: {$name}" => "模块未安装：{$name}"
"Module [{$name}] cannot be enabled from status [{$module->status}]" => "模块 [{$name}] 当前状态 [{$module->status}] 不允许启用。"
"Module [{$name}] cannot be disabled from status [{$module->status}]" => "模块 [{$name}] 当前状态 [{$module->status}] 不允许禁用。"
"Module [{$name}] cannot be uninstalled from status [{$module->status}]" => "模块 [{$name}] 当前状态 [{$module->status}] 不允许卸载。"
```

- [ ] **Step 2: Localize `ModuleRepository` review messages**

Use:

```php
"模块 [{$name}] 当前状态 [{$oldState}] 不允许审核通过。"
"模块 [{$name}] 当前状态 [{$oldState}] 不允许审核拒绝。"
```

- [ ] **Step 3: Localize `ModuleUpgrader` messages**

Use:

```php
"模块目标目录已存在：{$target}"
"模块未安装：{$name}"
"期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。"
"模块 [{$manifest->name()}] 当前状态 [{$status}] 不允许升级。"
"模块 [{$manifest->name()}] 新版本 [{$manifest->version()}] 必须大于当前版本 [{$currentVersion}]。"
"无法创建模块锁目录：{$dir}"
"无法打开模块锁：{$path}"
"模块 [{$module}] 正在升级中，请稍后再试。"
```

- [ ] **Step 4: Localize `ModuleRollbacker` messages**

Use:

```php
"模块未安装：{$name}"
"模块 [{$name}] 当前状态 [{$status}] 不允许回滚。"
"需要人工回滚：自动回滚最多支持一个缺失迁移。"
"迁移回滚后替换模块文件失败；当前文件已保留在 [{$currentSource}]：{$exception->getMessage()}"
"未找到模块备份：{$name}"
"期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。"
"无法创建模块锁目录：{$dir}"
"无法打开模块锁：{$path}"
"模块 [{$module}] 正在升级中，请稍后再试。"
```

- [ ] **Step 5: Run focused GREEN tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleLifecycleTest|ModuleCenterControllerTest|ModuleUpgradeTest|ModuleRollbackTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit

**Files:**
- Verify all modified module service and test files.

**Interfaces:**
- Consumes: completed P22 code and tests.
- Produces: committed and pushed P22 slice.

- [ ] **Step 1: Run syntax checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleInstaller.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleRepository.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleUpgrader.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleRollbacker.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleLifecycleTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleCenterControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleUpgradeTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleRollbackTest.php
```

Expected: no syntax errors.

- [ ] **Step 2: Run full suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diffs**

```powershell
git diff --check
git diff --stat
git diff -- app/Modules/ModuleInstaller.php app/Modules/ModuleRepository.php app/Modules/ModuleUpgrader.php app/Modules/ModuleRollbacker.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/Modules/ModuleCenterControllerTest.php tests/Feature/Modules/ModuleUpgradeTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p22-module-lifecycle-errors-chinese.md
```

Expected: no whitespace errors; diff scope matches this plan.

- [ ] **Step 4: Commit and push**

```powershell
git add app/Modules/ModuleInstaller.php app/Modules/ModuleRepository.php app/Modules/ModuleUpgrader.php app/Modules/ModuleRollbacker.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/Modules/ModuleCenterControllerTest.php tests/Feature/Modules/ModuleUpgradeTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p22-module-lifecycle-errors-chinese.md
git commit -m "fix: localize module lifecycle errors"
git push origin main
```

Expected: commit succeeds and push updates `origin/main`.

## Self-Review

- Spec coverage: covers module install, enable, admin controller lifecycle failure, admin review, upgrade, rollback, focused verification, full verification, review, commit, and push.
- Placeholder scan: no `TBD`, `TODO`, `implement later`, or vague implementation steps.
- Type consistency: no new public APIs; only message strings change.
