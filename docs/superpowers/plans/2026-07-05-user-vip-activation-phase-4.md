# User VIP and Activation Code Phase 4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add VIP plans, user VIP records, activation-code batches, activation-code generation, and user redemption that opens or extends VIP.

**Architecture:** Keep VIP entitlement changes in `App\User\VipService` and activation-code lifecycle in `App\User\ActivationCodeService`. Store activation-code secrets as hashes only; return plaintext codes only at generation time for admin export/copy. Keep commission hooks out of Phase 4 except storing source flags for Phase 5.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin dynamic admin controllers, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `vip_plan`, `user_vip_record`, `activation_code_batch`, `activation_code`, and `activation_code_redemption` tables.
- Models for all Phase 4 tables.
- `VipService` for VIP grant/extend/revoke and current VIP summary.
- `ActivationCodeService` for batch creation, code generation, code redemption, idempotency, and redemption audit rows.
- User endpoints:
  - `GET /user/vip`
  - `POST /user/activation-code/redeem`
- Admin read/list/create/update controls for VIP plans.
- Admin read/list/batch-create/generate/disable/void controls for activation codes.
- Tests for persistence, VIP extension rules, activation-code hash storage, expired/disabled/used code rejection, bound-user checks, idempotent redemption, user endpoints, and admin allowlists.

Excluded:
- Real payment gateway and paid VIP orders.
- Affiliate commission generation and balance settlement. Phase 4 only stores `is_commissionable`, `first_level_reward`, and `second_level_reward` on activation batches for Phase 5.
- Frontend member-center pages beyond API responses and EasyAdmin table shells.
- Exporting historical plaintext activation codes. Full plaintext exists only in the generation response.

---

## File Structure

- Create `database/migrations/2026_07_05_000004_create_user_vip_activation_phase_4_tables.php`
- Create `app/Models/VipPlan.php`
- Create `app/Models/UserVipRecord.php`
- Create `app/Models/ActivationCodeBatch.php`
- Create `app/Models/ActivationCode.php`
- Create `app/Models/ActivationCodeRedemption.php`
- Create `app/User/VipService.php`
- Create `app/User/ActivationCodeService.php`
- Create `app/Http/Controllers/user/VipController.php`
- Create `app/Http/Controllers/user/ActivationCodeController.php`
- Modify `routes/web.php`
- Create `app/Http/Controllers/admin/user/VipPlanController.php`
- Create `app/Http/Controllers/admin/user/ActivationCodeController.php`
- Create `resources/views/admin/user/vip-plan/index.blade.php`
- Create `resources/views/admin/user/activation-code/index.blade.php`
- Create `resources/views/admin/user/activation-code/redemptions.blade.php`
- Create `public/static/admin/js/user/vip-plan.js`
- Create `public/static/admin/js/user/activation-code.js`
- Create `tests/Feature/User/UserVipActivationTest.php`
- Create `tests/Feature/User/UserAdminVipActivationControllerTest.php`

---

## Task 1: VIP and Activation Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000004_create_user_vip_activation_phase_4_tables.php`
- Create: `app/Models/VipPlan.php`
- Create: `app/Models/UserVipRecord.php`
- Create: `app/Models/ActivationCodeBatch.php`
- Create: `app/Models/ActivationCode.php`
- Create: `app/Models/ActivationCodeRedemption.php`
- Test: `tests/Feature/User/UserVipActivationTest.php`

- [ ] **Step 1: Write failing persistence test**

Create `UserVipActivationTest` with `migrate:fresh`, then assert these columns:

```php
$this->assertTrue(Schema::hasColumns('vip_plan', [
    'name', 'level', 'duration_days', 'price', 'status', 'is_commissionable',
    'first_level_rate', 'second_level_rate', 'benefits_json', 'create_time', 'update_time',
]));
$this->assertTrue(Schema::hasColumns('user_vip_record', [
    'user_id', 'source_type', 'source_id', 'vip_plan_id', 'before_expires_at',
    'after_expires_at', 'duration_days', 'status', 'create_time',
]));
$this->assertTrue(Schema::hasColumns('activation_code_batch', [
    'name', 'vip_plan_id', 'duration_days', 'total_count', 'generated_count',
    'status', 'is_commissionable', 'first_level_reward', 'second_level_reward',
    'expires_at', 'create_admin_id', 'create_time', 'update_time',
]));
$this->assertTrue(Schema::hasColumns('activation_code', [
    'batch_id', 'code_hash', 'display_code_tail', 'status', 'max_uses', 'used_count',
    'bound_user_id', 'expires_at', 'create_time', 'update_time',
]));
$this->assertTrue(Schema::hasColumns('activation_code_redemption', [
    'activation_code_id', 'batch_id', 'user_id', 'vip_record_id', 'commission_source_id',
    'redeem_ip', 'result', 'error_message', 'create_time',
]));
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter tables
```

Expected: FAIL because tables/models do not exist.

- [ ] **Step 3: Implement migration and models**

Use normal EasyAdmin model patterns: `protected $guarded = []`, JSON casts for `benefits_json`, decimal casts for prices/rewards/rates, and `bootSoftDeletes() {}` for redemption audit rows.

Activation codes store `code_hash` only and `display_code_tail` for operator recognition. Add indexes:
- `vip_plan.status`, `vip_plan.level`
- `user_vip_record.user_id`, `user_vip_record.source_type/source_id`
- `activation_code_batch.status`, `activation_code_batch.vip_plan_id`
- unique `activation_code.code_hash`
- `activation_code.batch_id`, `activation_code.status`, `activation_code.bound_user_id`
- `activation_code_redemption.activation_code_id`, `activation_code_redemption.user_id`

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter tables
git add database/migrations/2026_07_05_000004_create_user_vip_activation_phase_4_tables.php app/Models/VipPlan.php app/Models/UserVipRecord.php app/Models/ActivationCodeBatch.php app/Models/ActivationCode.php app/Models/ActivationCodeRedemption.php tests/Feature/User/UserVipActivationTest.php
git commit -m "feat: add vip activation persistence"
```

## Task 2: VIP Service

**Files:**
- Create: `app/User/VipService.php`
- Modify: `tests/Feature/User/UserVipActivationTest.php`

- [ ] **Step 1: Add failing VIP service tests**

Cover:
- granting VIP to a non-VIP user sets `user_account.vip_level` and `vip_expires_at`;
- granting VIP to an active VIP user extends from current expiry;
- granting a higher-level plan upgrades `vip_level`;
- a VIP record stores before/after expiry, source type/id, plan id, duration, and `active` status;
- summary returns current level, expiry, active boolean, and plan/record count.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "vip"
```

Expected: FAIL because `VipService` does not exist.

- [ ] **Step 3: Implement `VipService`**

Public API:

```php
public function grant(int $userId, int $vipPlanId, string $sourceType, int $sourceId): array;
public function summary(int $userId): array;
```

Rules:
- plan must exist and have `status = active`;
- duration comes from plan `duration_days`;
- extension starts from `max(now(), current vip_expires_at)`;
- `after_expires_at = start + duration_days`;
- account snapshot updates in the same DB transaction as `user_vip_record`;
- return public arrays, not Eloquent models.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "vip"
git add app/User/VipService.php tests/Feature/User/UserVipActivationTest.php
git commit -m "feat: add user vip service"
```

## Task 3: Activation Code Service

**Files:**
- Create: `app/User/ActivationCodeService.php`
- Modify: `tests/Feature/User/UserVipActivationTest.php`

- [ ] **Step 1: Add failing activation service tests**

Cover:
- admin batch creation stores plan, duration, commission flags, reward amounts, expiry, total count, and admin id;
- generating codes returns plaintext codes once, stores only hashes and tails;
- redeeming a valid code grants VIP and writes redemption result `success`;
- redeeming same single-use code again fails and writes redemption result `failed`;
- disabled, expired, over-used, and wrong bound-user codes fail;
- user-facing failure does not reveal hash/plaintext internals.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "activation|redeem"
```

Expected: FAIL because `ActivationCodeService` does not exist.

- [ ] **Step 3: Implement `ActivationCodeService`**

Public API:

```php
public function createBatch(array $payload, ?int $adminId): array;
public function generateCodes(int $batchId, int $count, ?int $adminId): array;
public function redeem(array $payload, int $userId, string $ip): array;
```

Rules:
- generated plaintext format: `EA8-` + 24 uppercase random alpha-numeric chars in grouped chunks;
- database stores `hash('sha256', strtoupper(trim($code)))`;
- `display_code_tail` is last 6 normalized chars;
- batch must be `active` to generate/redeem;
- code must be `unused`, not expired, under `max_uses`, and either unbound or bound to the redeeming user;
- redemption uses DB transaction and row lock where supported;
- redeem increments `used_count`; single-use codes become `used`;
- successful redeem calls `VipService::grant($userId, $batch->vip_plan_id, 'activation_code', $activationCode->id)`;
- every redeem attempt writes `activation_code_redemption`.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "activation|redeem"
git add app/User/ActivationCodeService.php tests/Feature/User/UserVipActivationTest.php
git commit -m "feat: add activation code service"
```

## Task 4: User VIP and Activation Endpoints

**Files:**
- Create: `app/Http/Controllers/user/VipController.php`
- Create: `app/Http/Controllers/user/ActivationCodeController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserVipActivationTest.php`

- [ ] **Step 1: Add failing endpoint tests**

Cover:
- `GET /user/vip` requires a logged-in user session and returns current VIP summary;
- `POST /user/activation-code/redeem` requires logged-in user session and redeems a valid code;
- bad payload returns `code = 0`;
- routes use `CheckInstall` and throttle middleware.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "endpoint|routes"
```

Expected: FAIL with missing routes/controllers.

- [ ] **Step 3: Implement controllers and routes**

Add routes under the existing `user` prefix:

```php
Route::get('/vip', [\App\Http\Controllers\user\VipController::class, 'summary']);
Route::post('/activation-code/redeem', [\App\Http\Controllers\user\ActivationCodeController::class, 'redeem']);
```

Controllers read `session('user.id')`; if absent, return `$this->error('User login required.')`. Validate `code` as required string max 80.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php --filter "endpoint|routes"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
git add app/Http/Controllers/user/VipController.php app/Http/Controllers/user/ActivationCodeController.php routes/web.php tests/Feature/User/UserVipActivationTest.php
git commit -m "feat: add user vip activation endpoints"
```

## Task 5: Admin VIP and Activation Management

**Files:**
- Create: `app/Http/Controllers/admin/user/VipPlanController.php`
- Create: `app/Http/Controllers/admin/user/ActivationCodeController.php`
- Create: `resources/views/admin/user/vip-plan/index.blade.php`
- Create: `resources/views/admin/user/activation-code/index.blade.php`
- Create: `resources/views/admin/user/activation-code/redemptions.blade.php`
- Create: `public/static/admin/js/user/vip-plan.js`
- Create: `public/static/admin/js/user/activation-code.js`
- Create: `tests/Feature/User/UserAdminVipActivationControllerTest.php`

- [ ] **Step 1: Add failing admin tests**

Cover:
- `/admin/user/vip-plan/index` lists safe plan fields and supports safe search/sort;
- add/edit/modify allowed only for allowlisted VIP plan fields;
- `/admin/user/activation-code/index` lists safe code fields without `code_hash`;
- `/admin/user/activation-code/redemptions` lists redemption rows;
- batch create and generate endpoints return plaintext codes only in generation response;
- inherited delete/recycle/export behavior is either read-only or explicitly safe.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminVipActivationControllerTest.php
```

Expected: FAIL with missing controllers/assets.

- [ ] **Step 3: Implement admin controllers and assets**

Follow `admin/user/InviteController.php` allowlist style. Never expose `activation_code.code_hash`. For generated plaintext codes, return them only from `generate` response data and do not persist plaintext anywhere.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminVipActivationControllerTest.php
node --check public\static\admin\js\user\vip-plan.js
node --check public\static\admin\js\user\activation-code.js
git add app/Http/Controllers/admin/user/VipPlanController.php app/Http/Controllers/admin/user/ActivationCodeController.php resources/views/admin/user/vip-plan/index.blade.php resources/views/admin/user/activation-code/index.blade.php resources/views/admin/user/activation-code/redemptions.blade.php public/static/admin/js/user/vip-plan.js public/static/admin/js/user/activation-code.js tests/Feature/User/UserAdminVipActivationControllerTest.php
git commit -m "feat: add admin vip activation management"
```

## Task 6: Review and Full Verification

- [ ] **Step 1: Focused tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminVipActivationControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
```

- [ ] **Step 2: Full suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Lint/static**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\VipService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\ActivationCodeService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\VipController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\ActivationCodeController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\VipPlanController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\ActivationCodeController.php
node --check public\static\admin\js\user\vip-plan.js
node --check public\static\admin\js\user\activation-code.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- activation-code plaintext is never stored;
- activation-code hash comparisons normalize case and whitespace;
- used, disabled, expired, over-used, and wrong-bound-user codes fail;
- every redeem attempt writes a redemption audit row;
- successful redemption grants or extends VIP in one transaction;
- user account VIP snapshot matches the latest active `user_vip_record`;
- admin list endpoints do not expose `code_hash` or unsafe JSON internals;
- Phase 5 commission work is not implemented early.

- [ ] **Step 5: Review commit**

If review changes code, commit those changes. If no code changes are needed, use an empty checkpoint commit:

```powershell
git commit --allow-empty -m "chore: review user vip activation phase 4"
```

## Plan Self-Review

- Spec coverage: This plan covers Phase 4 VIP plans, user VIP records, activation batches/codes/redemptions, user redemption endpoint, and admin VIP/activation management.
- Placeholder scan: No task uses incomplete placeholder language; every task has files, commands, expected failures, and commit points.
- Type consistency: Table names, model names, service names, controller names, and route paths are consistent across tasks.
- Scope guard: Affiliate commission generation, balance ledger settlement, real payment orders, and withdrawals remain deferred to Phase 5 and Phase 6.
