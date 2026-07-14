# Versioned Desktop Token API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Qingyu desktop client's expiring Laravel cookie session with a versioned, scoped bearer-token API and rotating device refresh sessions.

**Architecture:** Laravel Sanctum owns 15-minute access tokens. Application-owned device sessions and single-use refresh-token rows provide 30-day rotation and reuse detection. The Qingyu module registers scoped `/api/v1/modules/qingyu-ip-agent/*` routes, while the Electron shim encrypts the token bundle with `safeStorage` and refreshes once on HTTP 401.

**Tech Stack:** PHP 8.3, Laravel 13, Laravel Sanctum 4.3, PHPUnit 12, SQLite/MySQL migrations, Node.js 22, Electron safeStorage, node:test.

## Global Constraints

- Preserve existing browser/admin Session and CSRF behavior.
- Do not remove legacy Qingyu client routes in this release.
- Access tokens expire after 15 minutes; refresh tokens expire after 30 days.
- Refresh tokens are single-use, hash-only, and rotate on every refresh.
- Protected module routes require both module and operation abilities.
- Never log access tokens, refresh tokens, passwords, activation codes, or reset secrets.
- Do not use subagents; execute inline as requested by the user.

---

### Task 1: Restore A Clean Backend Baseline

**Files:**
- Modify: `app/User/UserOpsDashboardService.php`
- Test: `tests/Feature/User/UserOpsVisibilityTest.php`

**Interfaces:**
- Produces: `UserOpsDashboardService::metrics()` compares timestamp database columns with Carbon values.

- [ ] Run the existing failing dashboard test and confirm `retryable_notifications` is `0` instead of `1`.
- [ ] Change the `available_at` comparison from `now()->timestamp` to `now()` so SQLite and MySQL compare the timestamp column consistently.
- [ ] Re-run `UserOpsVisibilityTest` and the full user/module suite.

### Task 2: Install Sanctum And Add Device Session Storage

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `app/Models/UserAccount.php`
- Create: `app/Models/UserApiSession.php`
- Create: `app/Models/UserApiRefreshToken.php`
- Create: `database/migrations/2026_07_14_000001_create_user_api_token_tables.php`
- Create: `config/user_api.php`
- Test: `tests/Feature/User/UserApiTokenAuthTest.php`

**Interfaces:**
- Produces: Sanctum `HasApiTokens` support on `UserAccount`.
- Produces: `UserApiSession` and `UserApiRefreshToken` records with revocation and expiry timestamps.
- Produces: module policy and exact access/refresh lifetimes in `config/user_api.php`.

- [ ] Add a failing test asserting token tables, model casts, configured lifetimes, and hashed token persistence.
- [ ] Run the test and confirm it fails because Sanctum/tables are absent.
- [ ] Require `laravel/sanctum:^4.3` with Composer 2.
- [ ] Add the migration, models, config, and `Authenticatable` plus `HasApiTokens` traits.
- [ ] Re-run the focused test and confirm it passes.

### Task 3: Add Stateless Authentication And Rotation Services

**Files:**
- Modify: `app/User/UserAuthService.php`
- Create: `app/User/UserApiProfileService.php`
- Create: `app/User/UserApiTokenService.php`
- Create: `app/User/UserApiException.php`
- Test: `tests/Feature/User/UserApiTokenAuthTest.php`

**Interfaces:**
- Produces: `UserAuthService::authenticate(array $payload, string $ip): array` without writing a web session.
- Produces: `UserApiTokenService::issue(UserAccount $user, string $module, array $device, string $ip, string $userAgent): array`.
- Produces: `UserApiTokenService::rotate(string $refreshToken, string $ip, string $userAgent): array`.
- Produces: `UserApiTokenService::revoke(UserAccount $user, ?int $accessTokenId): void`.
- Produces: `UserApiProfileService::payload(UserAccount|int $user): array`.

- [ ] Add failing tests for registration issuance, stateless login, access expiry metadata, refresh rotation, consumed-token reuse revocation, logout, and disabled-user refresh rejection.
- [ ] Run the tests and verify each failure is caused by the missing service behavior.
- [ ] Extract credential authentication from the existing session login path without changing browser behavior.
- [ ] Implement transactional token issuance/rotation and hash-only refresh storage.
- [ ] Implement the shared member/VIP profile payload.
- [ ] Run the focused token tests and the existing user auth tests.

### Task 4: Expose The Versioned Authentication API

**Files:**
- Modify: `bootstrap/app.php`
- Create: `routes/api.php`
- Create: `app/Http/Controllers/Api/V1/ApiController.php`
- Create: `app/Http/Controllers/Api/V1/AuthController.php`
- Create: `app/Http/Middleware/RequireApiAbility.php`
- Test: `tests/Feature/User/UserApiTokenAuthTest.php`

**Interfaces:**
- Produces: `/api/v1/auth/register|login|refresh|logout|profile|password/*`.
- Produces: `api.ability:<ability>` middleware alias.

- [ ] Add failing HTTP tests proving registration does not require CSRF, profile requires bearer auth, responses use the stable envelope, and rate limits are attached.
- [ ] Run tests and confirm the new routes are missing.
- [ ] Load `routes/api.php`, register the ability middleware, and implement public/protected route groups.
- [ ] Map validation/domain failures to 401, 403, 409, 422, and 429 without exposing internals.
- [ ] Run focused API tests and route inspection.

### Task 5: Add Scoped Qingyu Module API Routes

**Files:**
- Modify: `modules/QingyuIpAgent/src/Providers/QingyuIpAgentServiceProvider.php`
- Modify: `modules/QingyuIpAgent/src/Services/ClientApiService.php`
- Create: `modules/QingyuIpAgent/routes/api.php`
- Create: `modules/QingyuIpAgent/src/Controllers/ApiController.php`
- Test: `tests/Feature/Modules/QingyuIpAgentModuleTest.php`

**Interfaces:**
- Produces: versioned bootstrap, sample audio, activation, parse, and rewrite routes.
- Consumes: authenticated `UserAccount` from Sanctum and abilities from `config/user_api.php`.

- [ ] Add failing tests for route registration, unauthenticated rejection, module-scope rejection, activation permission, profile/VIP lookup, and successful authorized endpoint execution.
- [ ] Run the focused module tests and confirm the versioned routes are absent.
- [ ] Load module routes in the module provider and adapt `ClientApiService` to accept an explicit authenticated user instead of reading the web session for API calls.
- [ ] Keep legacy session methods operational for rollback compatibility.
- [ ] Run the module tests and full backend suite.

### Task 6: Migrate The Electron Shim To Encrypted Token State

**Files:**
- Modify: `E:/code/aigc-human/desktop-shell/takeover-core.js`
- Modify: `E:/code/aigc-human/desktop-shell/takeover.js`
- Modify: `E:/code/aigc-human/desktop-shell/takeover-core.test.js`
- Modify: `E:/code/aigc-human/scripts/real-e2e-api-production.js`

**Interfaces:**
- Produces: endpoint mappings for `/api/v1`.
- Produces: encrypted `{accessToken, refreshToken, accessExpiresAt, refreshExpiresAt, deviceId}` state.
- Produces: one automatic refresh and replay after HTTP 401.

- [ ] Change mapping and auth-state tests first; assert no Cookie or CSRF headers remain.
- [ ] Run `node --test desktop-shell/takeover-core.test.js` and verify expected failures.
- [ ] Add pure token bundle normalization/encryption helpers in `takeover-core.js`.
- [ ] Replace cookie session persistence in `takeover.js` with safeStorage token persistence, bearer headers, a single refresh lock, and one retry.
- [ ] Preserve the renderer compatibility token and original auth bridge.
- [ ] Update the real production E2E script to register/login/profile against `/api/v1`.
- [ ] Run node tests and syntax checks.

### Task 7: Review, Commit, Deploy, And Verify

**Files:**
- Verify all changed backend and desktop files.

**Interfaces:**
- Produces: reviewed commits in both repositories and verified production deployment.

- [ ] Run PHP syntax checks, Pint on newly created PHP files, `git diff --check`, and the complete module/user suite.
- [ ] Run Node tests, `node --check`, and inspect both repository diffs for secrets or unrelated changes.
- [ ] Commit the Laravel repository and the desktop repository separately with scoped messages.
- [ ] Back up production application files and database.
- [ ] Upload Composer 2, deploy backend files, run `composer install --no-dev`, migrations, package discovery, and cache clear.
- [ ] Restart the Qingyu desktop process so the shim reloads.
- [ ] Run a fresh production registration, login, profile, refresh, and protected module request.
- [ ] Confirm legacy web/admin routes remain healthy and report any external provider blocker separately.

