# P2 Production Environment Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent accidental production deployment with local/debug defaults by hardening environment templates and deployment acceptance checks.

**Architecture:** Keep the existing `scripts/deploy-acceptance.php` CLI and extend its current env check from `APP_KEY only` to a small explicit production readiness checklist. Add a dedicated production env example instead of making `.env.example` unusable for local installs.

**Tech Stack:** Laravel config files, PHP CLI script, PHPUnit feature tests, `.env` templates.

---

## File Structure

- Modify: `.env.example`
  - Set default locale to Chinese.
  - Keep local developer defaults but make unsafe production values clearly local.
- Create: `.env.production.example`
  - Safe production baseline: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `APP_LOCALE=zh_CN`, `EASYADMIN.CAPTCHA=true`, `EASYADMIN.RATE_LIMITING_STATUS=true`, no real secrets.
- Modify: `scripts/deploy-acceptance.php`
  - Parse `.env` values.
  - Require `APP_KEY`.
  - When `APP_ENV=production`, reject `APP_DEBUG=true`, `SESSION_ENCRYPT=false`, non-Chinese locale, empty `APP_URL`, and default local DB credentials.
  - Print separate `PASS env ...` lines for each check.
- Modify: `tests/Feature/User/DeployAcceptanceScriptTest.php`
  - Add tests for production env pass/fail behavior.
  - Update dry-run expected planned checks.
- Create: `tests/Feature/Environment/ProductionEnvironmentTemplateTest.php`
  - Assert production template exists and carries the safe baseline.

---

## Task 1: Production Env Template

**Files:**
- Create: `.env.production.example`
- Modify: `.env.example`
- Create: `tests/Feature/Environment/ProductionEnvironmentTemplateTest.php`

- [ ] **Step 1: Write failing tests**

Create a PHPUnit test that reads `.env.production.example` and asserts:

```php
$this->assertStringContainsString('APP_ENV=production', $env);
$this->assertStringContainsString('APP_DEBUG=false', $env);
$this->assertStringContainsString('APP_LOCALE=zh_CN', $env);
$this->assertStringContainsString('SESSION_ENCRYPT=true', $env);
$this->assertStringContainsString('EASYADMIN.CAPTCHA=true', $env);
$this->assertStringContainsString('EASYADMIN.RATE_LIMITING_STATUS=true', $env);
$this->assertStringNotContainsString('APP_KEY=base64:', $env);
```

Also assert `.env.example` uses:

```php
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=zh_CN
APP_FAKER_LOCALE=zh_CN
```

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\Environment\ProductionEnvironmentTemplateTest.php
```

Expected: FAIL because `.env.production.example` does not exist and `.env.example` still uses English locale.

- [ ] **Step 3: Implement template**

Add `.env.production.example` with production-safe placeholders:

```dotenv
APP_NAME=EasyAdmin8
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=PRC
APP_URL=https://example.com
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=zh_CN
APP_FAKER_LOCALE=zh_CN
SESSION_ENCRYPT=true
MAIL_MAILER=smtp
EASYADMIN.CAPTCHA=true
EASYADMIN.IS_CSRF=true
EASYADMIN.RATE_LIMITING_STATUS=true
```

Keep existing DB/mail/provider sections with placeholders, not real secrets.

- [ ] **Step 4: Verify GREEN**

Run the new template test and expect PASS.

---

## Task 2: Deploy Acceptance Env Hardening

**Files:**
- Modify: `scripts/deploy-acceptance.php`
- Modify: `tests/Feature/User/DeployAcceptanceScriptTest.php`

- [ ] **Step 1: Write failing tests**

Add tests that create a temporary `.env` fixture in a temp project copy or temporarily back up the real `.env` during the script process. The test should prove:

```php
APP_ENV=production
APP_DEBUG=true
SESSION_ENCRYPT=false
APP_LOCALE=en
DB_USERNAME=root
DB_PASSWORD=root
```

causes the script to fail with messages including:

```text
APP_DEBUG must be false in production.
SESSION_ENCRYPT must be true in production.
APP_LOCALE must be zh_CN in production.
Default root database credentials are not allowed in production.
```

Also add a production-safe `.env` test that passes the env stage and uses `--skip-migrate --skip-menu-sync --skip-portal --skip-admin`.

- [ ] **Step 2: Verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\DeployAcceptanceScriptTest.php --filter production_env
```

Expected: FAIL because the script currently checks only `APP_KEY`.

- [ ] **Step 3: Implement env parser and checks**

Add helper functions:

```php
function parseDotEnv(string $contents): array
function envValue(array $env, string $key, ?string $default = null): ?string
function envBool(array $env, string $key): ?bool
function assertEnvEquals(array $env, string $key, string $expected, string $message): void
```

In `checkDeploymentEnv()`:

```php
passDeploymentCheck('env APP_KEY present');

if (envValue($parsed, 'APP_ENV') === 'production') {
    assertEnvEquals($parsed, 'APP_DEBUG', 'false', 'APP_DEBUG must be false in production.');
    assertEnvEquals($parsed, 'SESSION_ENCRYPT', 'true', 'SESSION_ENCRYPT must be true in production.');
    assertEnvEquals($parsed, 'APP_LOCALE', 'zh_CN', 'APP_LOCALE must be zh_CN in production.');
    assertEnvNotDefaultRootDatabase($parsed);
}
```

Print `PASS env production hardening` after all production checks pass.

- [ ] **Step 4: Verify GREEN**

Run the focused deploy acceptance tests and expect PASS.

---

## Task 3: Full Verification and Commit

- [ ] **Step 1: Run P2 focused tests**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\Environment\ProductionEnvironmentTemplateTest.php tests\Feature\User\DeployAcceptanceScriptTest.php
```

- [ ] **Step 2: Run full SQLite suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Review**

```bash
git diff --check
git diff --stat
git diff
```

- [ ] **Step 4: Commit**

```bash
git add .env.example .env.production.example scripts/deploy-acceptance.php tests/Feature/Environment/ProductionEnvironmentTemplateTest.php tests/Feature/User/DeployAcceptanceScriptTest.php docs/superpowers/plans/2026-07-06-p2-production-env-hardening.md
git commit -m "chore: harden production environment checks"
```

---

## Self-Review

- Spec coverage: This plan covers the audit risk that local/debug env settings could be copied to production.
- Placeholder scan: No unresolved placeholders are left; template placeholders are intentional safe values.
- Scope check: Payment, homepage, and SEO are excluded so this P stays deploy-safety focused.
