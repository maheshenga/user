# User Invite Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the invitation layer for ordinary users: durable invite codes, invite binding during registration, two-level invite relationship snapshots, and admin read-only invite management.

**Architecture:** Keep invitation behavior inside `App\User\InviteService` and let `UserAuthService` call it only after the user account row is created. Store direct parent and grandparent snapshots in `user_invite_relation` so Phase 5 affiliate calculation can read two-level ancestry without recursive traversal.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin dynamic admin controllers, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `user_invite_code` and `user_invite_relation` tables.
- Models for invite codes and invite relations.
- Default permanent invite code for each registered user.
- Optional `invite_code` registration input.
- Binding direct parent and grandparent invite relationship at registration time.
- Rejection for missing, disabled, expired, overused, self, and circular invite codes.
- User endpoint to view the current user's invite code and direct invite records.
- Admin read-only invite code and relation list/detail surfaces.
- Tests for service behavior, user endpoints, and admin surfaces.

Excluded:
- Commission generation. Phase 5 will consume `user_invite_relation`.
- VIP plan behavior. Phase 4 will consume users but not alter invite binding.
- Public frontend pages.

---

## File Structure

- Create `database/migrations/2026_07_05_000002_create_user_invite_phase_2_tables.php`: creates invite code and relation tables.
- Create `app/Models/UserInviteCode.php`: Eloquent model for invite codes.
- Create `app/Models/UserInviteRelation.php`: Eloquent model for fixed user ancestry.
- Create `app/User/InviteCodeStatus.php`: status constants.
- Create `app/User/InviteService.php`: code generation, validation, default code creation, relationship binding, and query helpers.
- Modify `app/User/UserAuthService.php`: accept `invite_code`, create default code, and bind invite relation during registration.
- Modify `app/Http/Controllers/user/AuthController.php`: allow `invite_code` in registration and add invite info endpoints.
- Create `app/Http/Controllers/user/InviteController.php`: authenticated-ish session endpoint for current user's invite summary and invite records.
- Create `app/Http/Controllers/admin/user/InviteController.php`: admin read-only invite code/relation lists.
- Create `resources/views/admin/user/invite/index.blade.php`: invite code table shell.
- Create `resources/views/admin/user/invite/relations.blade.php`: invite relation table shell.
- Create `public/static/admin/js/user/invite.js`: invite code table config.
- Create `public/static/admin/js/user/invite-relations.js`: invite relation table config.
- Modify `routes/web.php`: add `/user/invite` and `/user/invite/records`.
- Modify `tests/Feature/User/UserAuthTest.php`: registration invite tests.
- Create `tests/Feature/User/UserInviteTest.php`: focused invite service and user endpoint tests.
- Create `tests/Feature/User/UserAdminInviteControllerTest.php`: admin invite list tests.

---

## Task 1: Invite Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000002_create_user_invite_phase_2_tables.php`
- Create: `app/Models/UserInviteCode.php`
- Create: `app/Models/UserInviteRelation.php`
- Test: `tests/Feature/User/UserInviteTest.php`

- [ ] **Step 1: Write failing schema/model test**

Create `tests/Feature/User/UserInviteTest.php` with:

```php
<?php

namespace Tests\Feature\User;

use App\Models\UserInviteCode;
use App\Models\UserInviteRelation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_invite_phase_2_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_invite_code'));
        $this->assertTrue(Schema::hasTable('user_invite_relation'));
        $this->assertTrue(Schema::hasColumns('user_invite_code', [
            'owner_user_id',
            'code',
            'type',
            'status',
            'max_uses',
            'used_count',
            'expires_at',
            'metadata_json',
        ]));
        $this->assertTrue(Schema::hasColumns('user_invite_relation', [
            'user_id',
            'parent_user_id',
            'grandparent_user_id',
            'invite_code_id',
            'level_path',
            'bind_type',
            'status',
        ]));
        $this->assertSame(0, UserInviteCode::query()->count());
        $this->assertSame(0, UserInviteRelation::query()->count());
    }
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserInviteTest.php --filter invite_phase_2_tables
```

Expected: FAIL because the invite tables and models do not exist.

- [ ] **Step 3: Create migration**

Create the migration with `user_invite_code` unique `code`, indexed `owner_user_id/status/type`, and `user_invite_relation` unique `user_id`.

- [ ] **Step 4: Create models**

Create `UserInviteCode` and `UserInviteRelation` with `protected $guarded = []`, table names, JSON/date casts, and `CarbonDate` casts for EasyAdmin timestamp fields.

- [ ] **Step 5: Verify GREEN**

Run the same focused test. Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_07_05_000002_create_user_invite_phase_2_tables.php app/Models/UserInviteCode.php app/Models/UserInviteRelation.php tests/Feature/User/UserInviteTest.php
git commit -m "feat: add user invite persistence"
```

## Task 2: Invite Service and Registration Binding

**Files:**
- Create: `app/User/InviteCodeStatus.php`
- Create: `app/User/InviteService.php`
- Modify: `app/User/UserAuthService.php`
- Modify: `tests/Feature/User/UserInviteTest.php`
- Modify: `tests/Feature/User/UserAuthTest.php`

- [ ] **Step 1: Add failing service tests**

Add tests proving:
- every registered user gets one active default invite code;
- registering with a valid invite code binds `parent_user_id`;
- registering through a child binds `grandparent_user_id`;
- invalid, disabled, expired, and exhausted invite codes are rejected;
- a user cannot create a circular relation.

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserInviteTest.php tests/Feature/User/UserAuthTest.php --filter "invite|register"
```

Expected: FAIL because `InviteService` and registration binding do not exist.

- [ ] **Step 3: Implement service**

Create `InviteService` with these public methods:

```php
public function createDefaultCode(UserAccount $user): UserInviteCode;
public function bindRegistration(UserAccount $user, ?string $inviteCode): ?UserInviteRelation;
public function inviteSummary(int $userId): array;
public function inviteRecords(int $userId, int $limit = 20): array;
```

Rules:
- default codes are `type = user`, `status = active`, `max_uses = 0`;
- generated code must be uppercase, random, and unique;
- `max_uses = 0` means unlimited;
- expired/disabled/exhausted codes throw `InvalidArgumentException`;
- self and circular binding throw `InvalidArgumentException`;
- binding writes `parent_user_id`, `grandparent_user_id`, `level_path`, `bind_type = register`, `status = active`;
- binding increments `used_count` in the same transaction as relation creation.

- [ ] **Step 4: Wire registration**

Modify `UserAuthService::register()` so registration runs in one transaction:
- create user;
- create default invite code for the new user;
- bind incoming `invite_code` if present;
- return `user`, `invite_code`, and optional `invite_relation`.

- [ ] **Step 5: Verify GREEN**

Run focused invite/user tests. Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add app/User/InviteCodeStatus.php app/User/InviteService.php app/User/UserAuthService.php tests/Feature/User/UserInviteTest.php tests/Feature/User/UserAuthTest.php
git commit -m "feat: bind user invite relationships"
```

## Task 3: User Invite Endpoints

**Files:**
- Create: `app/Http/Controllers/user/InviteController.php`
- Modify: `app/Http/Controllers/user/AuthController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserInviteTest.php`

- [ ] **Step 1: Add failing endpoint tests**

Add tests for:
- `POST /user/register` accepts `invite_code`;
- `GET /user/invite` returns current user's code and invite counts from `session('user.id')`;
- `GET /user/invite/records` returns direct invite records;
- unauthenticated invite endpoints return an error JSON with `code = 0`.

- [ ] **Step 2: Verify RED**

Run endpoint filter. Expected: FAIL with missing controller/routes.

- [ ] **Step 3: Implement controller and routes**

Add:

```php
Route::get('/invite', [\App\Http\Controllers\user\InviteController::class, 'summary']);
Route::get('/invite/records', [\App\Http\Controllers\user\InviteController::class, 'records']);
```

Keep these routes under the existing `Route::prefix('user')` group with `CheckInstall` and throttle middleware.

- [ ] **Step 4: Verify GREEN**

Run focused endpoint tests. Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/user/AuthController.php app/Http/Controllers/user/InviteController.php routes/web.php tests/Feature/User/UserInviteTest.php
git commit -m "feat: add user invite endpoints"
```

## Task 4: Admin Invite Management

**Files:**
- Create: `app/Http/Controllers/admin/user/InviteController.php`
- Create: `resources/views/admin/user/invite/index.blade.php`
- Create: `resources/views/admin/user/invite/relations.blade.php`
- Create: `public/static/admin/js/user/invite.js`
- Create: `public/static/admin/js/user/invite-relations.js`
- Create: `tests/Feature/User/UserAdminInviteControllerTest.php`

- [ ] **Step 1: Add failing admin tests**

Create tests proving:
- `/admin/user/invite/index` lists invite codes with safe fields only;
- `/admin/user/invite/relations` lists relationships;
- unsafe filters/sorts are ignored;
- inherited mutating actions are read-only/forbidden.

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserAdminInviteControllerTest.php
```

Expected: FAIL with missing controller/routes.

- [ ] **Step 3: Implement admin controller and assets**

Follow `admin/user/AccountController` allowlist style. Do not expose raw metadata or password-bearing user fields. Make inherited writes return read-only JSON and export abort with 403.

- [ ] **Step 4: Verify GREEN**

Run admin invite tests and JS syntax checks:

```powershell
node --check public/static/admin/js/user/invite.js
node --check public/static/admin/js/user/invite-relations.js
```

Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/admin/user/InviteController.php resources/views/admin/user/invite/index.blade.php resources/views/admin/user/invite/relations.blade.php public/static/admin/js/user/invite.js public/static/admin/js/user/invite-relations.js tests/Feature/User/UserAdminInviteControllerTest.php
git commit -m "feat: add admin invite management"
```

## Task 5: Review and Full Verification

**Files:**
- Review all P2 files.

- [ ] **Step 1: Run focused tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserInviteTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserAdminInviteControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/User/UserAuthTest.php
```

- [ ] **Step 2: Run full suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Run lint/static checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/InviteCodeStatus.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/InviteService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/user/InviteController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/admin/user/InviteController.php
node --check public/static/admin/js/user/invite.js
node --check public/static/admin/js/user/invite-relations.js
git diff --check
```

- [ ] **Step 4: Review**

Confirm:
- invite binding happens only at registration;
- every registered user receives a default invite code;
- a user has at most one invite relation;
- only two levels are stored for later commission use;
- admin surfaces are read-only in P2;
- no VIP, activation code, commission, or balance behavior is implemented in P2.

- [ ] **Step 5: Final commit if cleanup is needed**

```powershell
git add <changed-files>
git commit -m "chore: review user invite phase 2"
```

## Plan Self-Review

- Spec coverage: This plan covers Phase 2 invitation code, invitation binding, two-level relationship snapshots, user query endpoints, and admin invite management.
- Placeholder scan: No deferred implementation placeholders remain; each task has exact files, commands, and acceptance behavior.
- Type consistency: `InviteService`, `InviteCodeStatus`, `UserInviteCode`, `UserInviteRelation`, routes, and tests use consistent names.
- Scope guard: Commission, VIP, activation code redemption, password reset, and balances are intentionally deferred to P3-P6.
