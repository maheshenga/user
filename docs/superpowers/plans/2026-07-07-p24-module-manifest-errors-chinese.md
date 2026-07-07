# P24 Module Manifest Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Localize `module.json` manifest validation errors so module upload, install, upgrade, and discovery paths no longer expose English validation copy to administrators.

**Architecture:** Keep the behavior inside `App\Modules\ModuleManifest` unchanged and replace only exception messages emitted during JSON parsing, required-field validation, slug validation, and module-local path validation. Update existing unit and feature assertions first so the RED/GREEN cycle proves the user-facing message changes.

**Tech Stack:** Laravel/PHP 8.3, PHPUnit via Composer `test:sqlite`, existing module container services.

## Global Constraints

Do not use subagents for this task.
Use CodeGraph before grep or file discovery when locating code.
Use TDD: update tests first, verify RED, then change production code.
Use `apply_patch` for code and documentation edits.
Keep scope limited to `ModuleManifest` validation messages and directly affected tests.
Preserve exception types, validation semantics, file paths, and module lifecycle behavior.
Commit and push to `origin main` after fresh verification.

---

### Task 1: Localize ModuleManifest validation tests

**Files:**
- Modify: `tests/Unit/Modules/ModuleManifestTest.php`
- Modify: `tests/Feature/Modules/ModuleUpgradeTest.php`

**Interfaces:**
- Consumes: `App\Modules\ModuleManifest::fromFile(string $path): ModuleManifest`
- Produces: Chinese `InvalidArgumentException` messages for invalid `module.json`

- [ ] **Step 1: Write failing assertions in the unit test**

Update the existing exact-message assertions:

```php
$this->assertSame('module.json 缺少必填字段：schema_version', $exception->getMessage());
$this->assertSame('module.json 路径不能超出模块目录：assets', $exception->getMessage());
$this->assertSame('module.json 路径不能超出模块目录：entry', $exception->getMessage());
```

Add three focused tests:

```php
public function test_manifest_rejects_invalid_json_with_chinese_message(): void
{
    $path = base_path('storage/framework/testing-invalid-json-module.json');
    file_put_contents($path, '{');

    try {
        try {
            ModuleManifest::fromFile($path);
            $this->fail('Expected invalid JSON manifest to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('module.json 格式无效：Syntax error', $exception->getMessage());
        }
    } finally {
        @unlink($path);
    }
}

public function test_manifest_rejects_invalid_slug_fields_with_chinese_message(): void
{
    $path = base_path('storage/framework/testing-invalid-module-slug.json');
    file_put_contents($path, json_encode([
        'schema_version' => '1.0',
        'name' => 'Blog',
        'title' => 'Blog Module',
        'vendor' => 'easyadmin8',
        'version' => '1.0.0',
        'type' => 'private',
        'core_version' => '^8.0',
        'namespace' => 'Modules\\\\Blog',
        'admin_prefix' => 'blog',
    ], JSON_THROW_ON_ERROR));

    try {
        try {
            ModuleManifest::fromFile($path);
            $this->fail('Expected invalid slug manifest to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('module.json 字段格式无效：name', $exception->getMessage());
        }
    } finally {
        @unlink($path);
    }
}

public function test_manifest_rejects_non_object_json_with_chinese_message(): void
{
    $path = base_path('storage/framework/testing-non-object-module.json');
    file_put_contents($path, '"broken"');

    try {
        try {
            ModuleManifest::fromFile($path);
            $this->fail('Expected non-object manifest to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('module.json 必须是对象。', $exception->getMessage());
        }
    } finally {
        @unlink($path);
    }
}
```

Update the zip invalid manifest assertion:

```php
$this->assertStringContainsString('module.json 格式无效：Syntax error', $exception->getMessage());
```

- [ ] **Step 2: Run focused RED verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleManifestTest|ModuleUpgradeTest"
```

Expected: FAIL because `ModuleManifest` still emits English messages such as `module.json missing required field: schema_version` and raw `Syntax error`.

### Task 2: Localize ModuleManifest production messages

**Files:**
- Modify: `app/Modules/ModuleManifest.php`

**Interfaces:**
- Consumes: existing validation flow in `ModuleManifest::fromFile()`
- Produces: same exception types with Chinese messages

- [ ] **Step 1: Implement minimal message changes**

Apply these mappings only:

```php
throw new InvalidArgumentException('module.json 格式无效：'.$exception->getMessage(), 0, $exception);
throw new InvalidArgumentException('module.json 必须是对象。');
throw new InvalidArgumentException("module.json 缺少必填字段：{$field}");
throw new InvalidArgumentException("module.json 字段格式无效：{$field}");
throw new InvalidArgumentException("module.json 路径不能超出模块目录：{$field}");
```

- [ ] **Step 2: Run GREEN focused verification**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="ModuleManifestTest|ModuleUpgradeTest"
```

Expected: PASS for the focused suite.

### Task 3: Verify, review, commit, and push

**Files:**
- Verify: `app/Modules/ModuleManifest.php`
- Verify: `tests/Unit/Modules/ModuleManifestTest.php`
- Verify: `tests/Feature/Modules/ModuleUpgradeTest.php`
- Commit: plan, source, tests

- [ ] **Step 1: Syntax check touched PHP files**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/Modules/ModuleManifest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Unit/Modules/ModuleManifestTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/Modules/ModuleUpgradeTest.php
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
git diff -- app/Modules/ModuleManifest.php tests/Unit/Modules/ModuleManifestTest.php tests/Feature/Modules/ModuleUpgradeTest.php docs/superpowers/plans/2026-07-07-p24-module-manifest-errors-chinese.md
```

Expected: no whitespace errors; diff is limited to P24 scope.

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add app/Modules/ModuleManifest.php tests/Unit/Modules/ModuleManifestTest.php tests/Feature/Modules/ModuleUpgradeTest.php docs/superpowers/plans/2026-07-07-p24-module-manifest-errors-chinese.md
git commit -m "fix: localize module manifest errors"
git push origin main
```

Expected: commit is created and pushed to `origin/main`.

## Self-Review

Spec coverage: JSON syntax, object shape, missing field, slug field, and path escape validation messages are covered.
Placeholder scan: no `TBD`, `TODO`, or deferred steps remain.
Type consistency: all steps use existing `ModuleManifest::fromFile(string $path)` and `InvalidArgumentException` behavior.
