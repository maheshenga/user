# P23 Module Package Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This project run is explicitly inline-only: do not use subagents.

**Goal:** Localize module package upload, zip extraction, file replacement, deletion safety, and reserved admin prefix errors so administrators do not see English messages during module package operations.

**Architecture:** Keep package safety behavior unchanged. Replace only human-facing exception strings in `ModuleZipExtractor`, `ModuleFileStore`, and `ReservedAdminPrefixRegistry`, then update existing feature tests that already exercise those failure paths.

**Tech Stack:** Laravel, PHP 8.3, PHPUnit feature tests, SQLite test database, ZipArchive.

## Global Constraints

- No subagents for this execution; implement directly in this session.
- Use TDD: update failing assertions before production code.
- Do not change zip limits, symlink protections, allowed roots, deletion safety, reserved prefix detection, or module status behavior.
- Do not localize `ModuleManifest` schema validation in this P; that remains a separate follow-up.
- Only touch P23 files listed below unless focused verification proves a direct dependency must change.

---

## File Structure

- Modify: `tests/Feature/Modules/ModulePackageTest.php`
  - Updates zip safety and file-store safety expectations to Chinese.
- Modify: `tests/Feature/Modules/ModuleUpgradeTest.php`
  - Updates reserved admin prefix upgrade/install expectations and persisted `last_error`.
- Modify: `tests/Feature/Modules/ModuleLifecycleTest.php`
  - Updates reserved admin prefix CLI expectations to Chinese.
- Modify: `tests/Feature/Modules/ModuleRollbackTest.php`
  - Updates rollback file-copy symlink failure expectation to Chinese because rollback uses `ModuleFileStore`.
- Modify: `app/Modules/ModuleZipExtractor.php`
  - Localizes zip open, extraction, unsafe entry, size-limit, and missing `module.json` errors.
- Modify: `app/Modules/ModuleFileStore.php`
  - Localizes backup, replace, delete, copy, symlink, and filesystem operation errors.
- Modify: `app/Modules/ReservedAdminPrefixRegistry.php`
  - Localizes reserved admin prefix errors used by install/upgrade paths.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/Modules/ModulePackageTest.php`
- Modify: `tests/Feature/Modules/ModuleUpgradeTest.php`
- Modify: `tests/Feature/Modules/ModuleLifecycleTest.php`
- Modify: `tests/Feature/Modules/ModuleRollbackTest.php`

**Interfaces:**
- Consumes: current package and file-store exception messages.
- Produces: failing expectations for Chinese module package errors.

- [ ] **Step 1: Update zip extractor expectations**

Use these Chinese expectations in `ModulePackageTest`:

```php
$this->expectExceptionMessage('模块 zip 包包含不安全条目');
$this->expectExceptionMessage('模块 zip 包过大');
$this->expectExceptionMessage('模块 zip 包包含不安全条目');
```

- [ ] **Step 2: Update file store replacement/delete expectations**

Use:

```php
$this->expectExceptionMessage('替换目标不在允许的模块目录内');
$this->expectExceptionMessage('替换目标包含符号链接父目录');
$this->expectExceptionMessage('替换目标包含点号路径段');
$this->expectExceptionMessage('拒绝复制符号链接');
$this->expectExceptionMessage('替换目标不能包含源目录，也不能位于源目录内');
$this->expectExceptionMessage('删除路径不能是安全根目录');
$this->expectExceptionMessage('删除路径不在允许的模块目录内');
```

- [ ] **Step 3: Update reserved prefix expectations**

Use:

```php
$this->assertStringContainsString('保留的后台前缀 [admin]', $exception->getMessage());
'last_error' => '模块 [blog] 不能使用保留的后台前缀 [admin]，该前缀已被内置后台路由占用。',
$this->artisan(...)->expectsOutputToContain("保留的后台前缀 [{$prefix}]")
```

- [ ] **Step 4: Update rollback symlink copy expectation**

Use:

```php
$this->assertStringContainsString('拒绝复制符号链接', $exception->getMessage());
```

- [ ] **Step 5: Run focused RED tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModulePackageTest|ModuleUpgradeTest|ModuleLifecycleTest|ModuleRollbackTest"
```

Expected: FAIL because package/file-store/reserved-prefix messages are still English.

## Task 2: GREEN Implementation

**Files:**
- Modify: `app/Modules/ModuleZipExtractor.php`
- Modify: `app/Modules/ModuleFileStore.php`
- Modify: `app/Modules/ReservedAdminPrefixRegistry.php`

**Interfaces:**
- Consumes: existing exceptions and safety checks.
- Produces: same exception types with Chinese messages.

- [ ] **Step 1: Localize `ModuleZipExtractor` messages**

Use these messages:

```php
'ZipArchive 扩展不可用。'
"无法创建模块解压目录：{$target}"
'无法打开模块 zip 包。'
'模块 zip 包过大：条目数量超过限制。'
"模块 zip 包包含不安全条目：index {$index}"
'模块 zip 包过大：解压后总大小超过限制。'
"模块 zip 包包含不安全条目：{$name}"
'无法解压模块 zip 包。'
'模块 zip 包过大：单个条目解压后大小超过限制。'
'模块 zip 包中未找到 module.json。'
```

- [ ] **Step 2: Localize `ModuleFileStore` messages**

Use these messages:

```php
"模块目录不存在：{$source}"
"替换目录不存在：{$source}"
'替换目标包含点号路径段。'
'替换源目录包含点号路径段。'
'替换目标必须不同于源目录。'
'替换目标不能包含源目录，也不能位于源目录内。'
'替换目标不在允许的模块目录内。'
'删除路径包含点号路径段。'
'删除路径不在允许的模块目录内。'
'删除路径不能是安全根目录。'
"无法删除目录：{$path}"
"期望目录，实际是文件：{$source}"
"源目录不存在：{$source}"
"目标目录已存在：{$target}"
"无法创建目录：{$target}"
"无法读取目录：{$source}"
"拒绝复制符号链接：{$from}"
"无法复制文件：{$from}"
"无法删除路径：{$path}"
'替换目标包含符号链接父目录。'
```

- [ ] **Step 3: Localize `ReservedAdminPrefixRegistry` messages**

Use:

```php
"保留的后台前缀 [{$prefix}] 不能被模块使用。"
"模块 [{$moduleName}] 不能使用保留的后台前缀 [{$prefix}]，该前缀已被内置后台路由占用。"
```

- [ ] **Step 4: Run focused GREEN tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModulePackageTest|ModuleUpgradeTest|ModuleLifecycleTest|ModuleRollbackTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit

**Files:**
- Verify all modified P23 service and test files.

**Interfaces:**
- Consumes: completed P23 code and tests.
- Produces: committed and pushed P23 slice.

- [ ] **Step 1: Run syntax checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleZipExtractor.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleFileStore.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ReservedAdminPrefixRegistry.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModulePackageTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleUpgradeTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleLifecycleTest.php
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
git diff -- app/Modules/ModuleZipExtractor.php app/Modules/ModuleFileStore.php app/Modules/ReservedAdminPrefixRegistry.php tests/Feature/Modules/ModulePackageTest.php tests/Feature/Modules/ModuleUpgradeTest.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p23-module-package-errors-chinese.md
```

Expected: no whitespace errors; diff scope matches this plan.

- [ ] **Step 4: Commit and push**

```powershell
git add app/Modules/ModuleZipExtractor.php app/Modules/ModuleFileStore.php app/Modules/ReservedAdminPrefixRegistry.php tests/Feature/Modules/ModulePackageTest.php tests/Feature/Modules/ModuleUpgradeTest.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/Modules/ModuleRollbackTest.php docs/superpowers/plans/2026-07-07-p23-module-package-errors-chinese.md
git commit -m "fix: localize module package errors"
git push origin main
```

Expected: commit succeeds and push updates `origin/main`.

## Self-Review

- Spec coverage: covers zip package safety, file replacement safety, delete safety, reserved prefix safety, rollback file-store propagated errors, focused verification, full verification, review, commit, and push.
- Placeholder scan: no `TBD`, `TODO`, `implement later`, or vague implementation steps.
- Type consistency: no public API changes; only message strings change.
