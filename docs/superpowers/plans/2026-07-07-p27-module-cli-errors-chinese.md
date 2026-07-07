# P27 Module CLI Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Localize module Artisan command output so deployment and operations workflows do not show English module lifecycle messages.

**Architecture:** Keep command names, arguments, exit codes, and service calls unchanged in `routes/console.php`. Update feature tests first to assert Chinese output for missing module tables and successful install, enable, disable, and uninstall commands.

**Tech Stack:** Laravel/PHP 8.3, PHPUnit via Composer `test:sqlite`, Artisan command tests.

## Global Constraints

Do not use subagents.
Use CodeGraph before grep or file discovery when locating code.
Use TDD: update tests first, verify RED, then change command output.
Use `apply_patch` for edits.
Keep scope limited to module CLI command output in `routes/console.php` and directly affected module lifecycle tests.
Preserve command names, arguments, exit codes, module lifecycle behavior, database writes, and table output columns.
Commit and push to `origin main` after fresh verification.

---

### Task 1: Add RED coverage for Chinese module CLI output

**Files:**
- Modify: `tests/Feature/Modules/ModuleLifecycleTest.php`

**Interfaces:**
- Consumes: Artisan commands `module:list`, `module:discover`, `module:install`, `module:enable`, `module:disable`, `module:uninstall`
- Produces: Chinese output assertions

- [ ] **Step 1: Update missing-table command assertions**

Replace:

```php
->expectsOutputToContain('Module tables are not installed')
```

with:

```php
->expectsOutputToContain('模块数据表未安装，请先运行模块迁移。')
```

- [ ] **Step 2: Add success output assertions**

Update lifecycle commands:

```php
$this->artisan('module:install', ['name' => 'blog'])
    ->expectsOutputToContain('模块已安装：blog')
    ->assertExitCode(0);

$this->artisan('module:enable', ['name' => 'blog'])
    ->expectsOutputToContain('模块已启用：blog')
    ->assertExitCode(0);

$this->artisan('module:disable', ['name' => 'blog'])
    ->expectsOutputToContain('模块已禁用：blog')
    ->assertExitCode(0);

$this->artisan('module:uninstall', ['name' => 'blog'])
    ->expectsOutputToContain('模块已卸载：blog')
    ->assertExitCode(0);
```

- [ ] **Step 3: Run focused RED verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleLifecycleTest"
```

Expected: FAIL because `routes/console.php` still emits English command output.

### Task 2: Localize module CLI command output

**Files:**
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: existing module Artisan closures
- Produces: same exit codes with Chinese messages

- [ ] **Step 1: Replace command output literals**

Use these messages:

```php
$this->error('模块数据表未安装，请先运行模块迁移。');
$this->info("模块已安装：{$name}");
$this->info("模块已启用：{$name}");
$this->info("模块已禁用：{$name}");
$this->info("模块已卸载：{$name}");
```

- [ ] **Step 2: Run focused GREEN verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleLifecycleTest"
```

Expected: PASS for the focused suite.

### Task 3: Verify, review, commit, and push

**Files:**
- Verify: `routes/console.php`
- Verify: `tests/Feature/Modules/ModuleLifecycleTest.php`

- [ ] **Step 1: Syntax check touched PHP files**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l routes/console.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleLifecycleTest.php
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
git diff -- routes/console.php tests/Feature/Modules/ModuleLifecycleTest.php docs/superpowers/plans/2026-07-07-p27-module-cli-errors-chinese.md
```

Expected: no whitespace errors; diff is limited to P27 scope.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add routes/console.php tests/Feature/Modules/ModuleLifecycleTest.php docs/superpowers/plans/2026-07-07-p27-module-cli-errors-chinese.md
git commit -m "fix: localize module cli output"
git push origin main
```

Expected: commit is created and pushed to `origin/main`.

## Self-Review

Spec coverage: missing module table output and successful install, enable, disable, and uninstall outputs are covered.
Placeholder scan: no deferred implementation placeholders remain.
Type consistency: all steps use existing Artisan command names and existing `ModuleLifecycleTest`.
