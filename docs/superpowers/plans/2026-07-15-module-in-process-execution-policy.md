# Module In-Process Execution Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fail closed when production attempts to enable or load third-party modules in the Laravel host process.

**Architecture:** A focused `ModuleExecutionPolicy` evaluates persisted module trust levels against a production allowlist. Both lifecycle enablement and runtime discovery call the policy so normal admin actions and stale database state cannot bypass it.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, PHPUnit 12.

## Global Constraints

- Keep upload, manual review, immutable release, signature, and install behavior unchanged.
- Default production in-process trust levels to `core`, `official`, and `private`.
- Keep `partner` and `community` available outside production.
- Do not claim OS-level isolation or add a Worker implementation in this repository.
- Preserve existing lifecycle audit and `last_error` behavior.

---

### Task 1: Specify the Policy with Failing Tests

**Files:**
- Create: `tests/Feature/Modules/ModuleExecutionPolicyTest.php`

**Interfaces:**
- Consumes: `ModuleInstaller::enable(string $name, ?int $actorId = null): void` and `ModuleManager::enabled(bool $forceIntegrityCheck = false): array`.
- Produces: behavioral coverage for the new `ModuleExecutionPolicy` contract.

- [x] **Step 1: Add production enable rejection tests**

Create disabled `community` and `partner` `SystemModule` rows, set `$this->app['env'] = 'production'`, call `ModuleInstaller::enable()`, and assert an `InvalidArgumentException` containing `不允许在主进程内运行` while status remains `disabled`.

- [x] **Step 2: Add stale runtime row rejection test**

Create an enabled `community` row, call `ModuleManager::enabled()`, assert the module is absent, and assert `last_error` contains the policy message.

- [x] **Step 3: Add trusted and non-production compatibility tests**

Install a valid immutable `private` fixture before switching to production, assert it can be enabled, and directly assert a development `community` row is accepted by `ModuleExecutionPolicy`.

- [x] **Step 4: Run the focused test and verify RED**

Run:

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Modules/ModuleExecutionPolicyTest.php
```

Expected: failure because `App\Modules\ModuleExecutionPolicy` does not exist and current enable/runtime paths do not enforce the trust allowlist.

### Task 2: Implement and Enforce the Policy

**Files:**
- Create: `app/Modules/ModuleExecutionPolicy.php`
- Modify: `config/modules.php`
- Modify: `app/Modules/ModuleInstaller.php`
- Modify: `app/Modules/ModuleManager.php`

**Interfaces:**
- Produces: `ModuleExecutionPolicy::isInProcessAllowed(SystemModule $module): bool`.
- Produces: `ModuleExecutionPolicy::assertInProcessAllowed(SystemModule $module): void`.

- [x] **Step 1: Add the production allowlist configuration**

Add:

```php
'production_in_process_trust_levels' => ['core', 'official', 'private'],
```

- [x] **Step 2: Implement the focused policy**

The policy returns `true` outside production. In production it normalizes the configured allowlist to unique non-empty strings, evaluates `trust_level` with a legacy `type` fallback, and throws:

```text
模块 [name] 的信任级别 [level] 不允许在生产环境主进程内运行；请使用独立模块执行服务。
```

- [x] **Step 3: Enforce policy during enablement**

Inject `ModuleExecutionPolicy` into `ModuleInstaller` and call `assertInProcessAllowed()` after lifecycle status validation and before immutable release checks, manifest loading, menu synchronization, and status mutation.

- [x] **Step 4: Enforce policy during runtime discovery**

Inject `ModuleExecutionPolicy` into `ModuleManager` and call `assertInProcessAllowed()` inside the existing `try` at the beginning of `manifestFromRow()`. Preserve the current catch behavior that writes `last_error` and returns `null`.

- [x] **Step 5: Run the focused test and verify GREEN**

Run the Task 1 command and expect all policy tests to pass.

### Task 3: Regression Review and Commit

**Files:**
- Review: all files changed by Tasks 1 and 2.

- [x] **Step 1: Run formatting checks**

```bash
php vendor/bin/pint --test app/Modules/ModuleExecutionPolicy.php app/Modules/ModuleInstaller.php app/Modules/ModuleManager.php config/modules.php tests/Feature/Modules/ModuleExecutionPolicyTest.php
```

- [x] **Step 2: Run module regressions**

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Modules/ModuleExecutionPolicyTest.php tests/Feature/Modules/ModuleReleaseTest.php tests/Feature/Modules/ModuleRuntimeTest.php tests/Feature/Modules/QingyuIpAgentModuleTest.php
```

- [x] **Step 3: Inspect behavior and security boundaries**

Confirm production is fail-closed, non-production behavior is unchanged, internal private modules pass, both enforcement points use one policy, lifecycle failures are audited, and no migration or secret is introduced.

- [x] **Step 4: Inspect the diff**

Run `git diff --check`, inspect `git diff --stat` and `git diff`, and scan staged content for credentials or private keys.

- [x] **Step 5: Commit implementation**

```bash
git add app/Modules/ModuleExecutionPolicy.php app/Modules/ModuleInstaller.php app/Modules/ModuleManager.php config/modules.php tests/Feature/Modules/ModuleExecutionPolicyTest.php docs/superpowers/plans/2026-07-15-module-in-process-execution-policy.md
git commit -m "fix: block third-party in-process modules"
```
