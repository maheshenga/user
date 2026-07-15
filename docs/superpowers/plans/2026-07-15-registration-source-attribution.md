# Registration Source Attribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent anonymous registration payloads from choosing `user_account.source_module` while preserving server-selected module registration.

**Architecture:** Separate untrusted registration fields from trusted source context at the `UserAuthService` method boundary. Core, versioned API, and Qingyu call sites each establish source explicitly according to their server-side context.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, PHPUnit 12.

## Global Constraints

- Keep the existing `source_module` column and response field.
- Default omitted source context to `core`.
- Never read module attribution from the registration payload.
- Keep `ModuleApiPolicy::assertAvailable()` before versioned API registration.
- Do not rewrite historical records in this batch.

---

### Task 1: Prove the Attribution Vulnerability

**Files:**
- Modify: `tests/Feature/User/UserAuthTest.php`

**Interfaces:**
- Consumes: `UserAuthService::register(array $payload, string $ip): array`.
- Produces: failing coverage for public and direct-service spoof attempts.

- [x] **Step 1: Add a public endpoint spoof regression test**

POST `/user/register` with a valid account and `source_module=qingyu_ip_agent`, then assert both the JSON response and `user_account.source_module` are `core`.

- [x] **Step 2: Add a direct-service spoof regression test**

Call `UserAuthService::register()` with a payload containing `source_module=spoofed_module` but no explicit context argument, then assert the stored value is `core`.

- [x] **Step 3: Run the two tests and verify RED**

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/User/UserAuthTest.php --filter source_module
```

Expected: both new tests fail because the current controller and service trust the payload value.

### Task 2: Separate Payload from Trusted Context

**Files:**
- Modify: `app/User/UserAuthService.php`
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `modules/QingyuIpAgent/src/Services/ClientApiService.php`

**Interfaces:**
- Produces: `UserAuthService::register(array $payload, string $ip, string $sourceModule = 'core'): array`.

- [x] **Step 1: Change the service contract**

Add the third argument with a `core` default and call `normalizeSourceModule($sourceModule)`. Remove all reads of `$payload['source_module']`.

- [x] **Step 2: Lock public registration to core**

Remove `source_module` from `request()->only()`, validation rules, and validation messages. Call the service with its default source context.

- [x] **Step 3: Pass versioned module context explicitly**

Keep validating `module` and calling `ModuleApiPolicy::assertAvailable()`, then call:

```php
$registered = $auth->register($payload, $request->ip(), $payload['module']);
```

- [x] **Step 4: Pass the Qingyu constant explicitly**

Remove payload mutation and call:

```php
$registered = $this->auth->register($payload, $ip, self::MODULE);
```

- [x] **Step 5: Run the spoof tests and verify GREEN**

Run the Task 1 command and expect both spoof tests to pass.

### Task 3: Migrate Intentional Internal Callers

**Files:**
- Modify: `tests/Feature/User/UserAuthTest.php`
- Modify: `tests/Feature/User/UserApiTokenAuthTest.php`
- Modify: `tests/Feature/Modules/QingyuIpAgentModuleTest.php`

- [x] **Step 1: Update the source-attribution service test**

Pass `vip_center` as the third argument and keep assertions that it is stored and returned.

- [x] **Step 2: Update token test fixtures**

Where helpers intentionally create Qingyu-owned users, remove the payload key and pass `qingyu_ip_agent` as the third argument.

- [x] **Step 3: Update Qingyu module test fixtures**

Convert intentional `qingyu_ip_agent` and explicit `core` setup calls to the third argument. Leave ordinary core registrations on the default.

- [x] **Step 4: Add explicit context validation coverage**

Assert a valid explicit module source is persisted and an invalid explicit source still throws the existing Chinese validation error.

### Task 4: Review, Verify, and Commit

- [x] **Step 1: Run Pint on changed PHP files**

- [x] **Step 2: Run `UserAuthTest`, `UserApiTokenAuthTest`, and `QingyuIpAgentModuleTest`**

- [x] **Step 3: Run the complete user and module test directories**

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/User tests/Feature/Modules tests/Unit/Modules
```

- [x] **Step 4: Review call sites and staged diff**

Confirm no production caller passes attribution inside payload, public registration is core, module policy still runs first, no migration is introduced, and staged content contains no credentials.

- [x] **Step 5: Commit implementation**

```bash
git commit -m "fix: protect registration source attribution"
```
