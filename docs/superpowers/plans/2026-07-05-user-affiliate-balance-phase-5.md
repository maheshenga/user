# User Affiliate Balance Phase 5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two-level affiliate commission generation, admin-only commission review, and transactional user balance ledger settlement.

**Architecture:** Keep commission rules in `App\User\AffiliateService` and all balance snapshot changes in `App\User\BalanceLedgerService`. Activation-code redemption remains the first commission source; settlement is never automatic and only admin approval moves pending commission into available balance with a ledger row. Admin controllers use EasyAdmin dynamic routing and strict allowlists so review and adjustment surfaces are auditable.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin dynamic admin controllers, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `affiliate_commission` table for two-level pending commission records.
- `user_balance_ledger` table for every available/frozen balance mutation.
- `BalanceLedgerService` for credit, debit, freeze, unfreeze, and admin adjustment with account snapshots and ledger rows in one transaction.
- `AffiliateService` for activation-code commission generation, idempotency, admin approval/settlement, admin rejection, and commission reversal entry point.
- Activation-code redemption integration: commissionable batches generate level 1 and level 2 pending commissions after successful VIP redemption.
- User endpoints:
  - `GET /user/balance`
  - `GET /user/balance/ledger`
- Admin management:
  - `/admin/user/commission/index`
  - `/admin/user/commission/approve`
  - `/admin/user/commission/reject`
  - `/admin/user/balance/index`
  - `/admin/user/balance/adjust`
- Focused feature tests plus full-suite verification.

Excluded:
- Real paid VIP order source and payment callbacks. Phase 5 exposes `AffiliateService::createForVipOrder(...)` as a service entry point but does not add payment UI.
- Withdrawal workflow. Freeze/unfreeze methods are implemented as balance primitives, while withdrawal audit and payout screens stay in Phase 6.
- Batch review operations and statistics dashboards. Phase 6 will add batch operations and operational reporting.
- Third-party review. Commission review is admin permission/admin session only.

---

## File Structure

- Create `database/migrations/2026_07_05_000005_create_user_affiliate_balance_phase_5_tables.php`
- Create `app/Models/AffiliateCommission.php`
- Create `app/Models/UserBalanceLedger.php`
- Create `app/User/BalanceLedgerService.php`
- Create `app/User/AffiliateService.php`
- Modify `app/User/ActivationCodeService.php`
- Create `app/Http/Controllers/user/BalanceController.php`
- Modify `routes/web.php`
- Create `app/Http/Controllers/admin/user/CommissionController.php`
- Create `app/Http/Controllers/admin/user/BalanceController.php`
- Create `resources/views/admin/user/commission/index.blade.php`
- Create `resources/views/admin/user/balance/index.blade.php`
- Create `public/static/admin/js/user/commission.js`
- Create `public/static/admin/js/user/balance.js`
- Create `tests/Feature/User/UserAffiliateBalanceTest.php`
- Create `tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php`

---

## Task 1: Affiliate and Balance Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000005_create_user_affiliate_balance_phase_5_tables.php`
- Create: `app/Models/AffiliateCommission.php`
- Create: `app/Models/UserBalanceLedger.php`
- Test: `tests/Feature/User/UserAffiliateBalanceTest.php`

- [ ] **Step 1: Write failing persistence test**

Create `UserAffiliateBalanceTest` with `RefreshDatabase`/project test helpers consistent with existing user feature tests, then assert:

```php
$this->assertTrue(Schema::hasColumns('affiliate_commission', [
    'id', 'source_type', 'source_id', 'buyer_user_id', 'beneficiary_user_id',
    'level', 'amount', 'status', 'reason', 'audit_admin_id', 'audited_at',
    'settled_ledger_id', 'reversed_commission_id', 'create_time', 'update_time',
]));
$this->assertTrue(Schema::hasColumns('user_balance_ledger', [
    'id', 'user_id', 'direction', 'amount', 'balance_before', 'balance_after',
    'frozen_before', 'frozen_after', 'type', 'source_type', 'source_id',
    'remark', 'admin_id', 'create_time',
]));
```

Also assert unique idempotency for commission source:

```php
$indexes = collect(DB::select("PRAGMA index_list('affiliate_commission')"))
    ->pluck('name')
    ->implode(',');
$this->assertStringContainsString('affiliate_commission_source_level_beneficiary_unique', $indexes);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter tables
```

Expected: FAIL because `affiliate_commission` and `user_balance_ledger` do not exist.

- [ ] **Step 3: Implement migration and models**

Migration rules:
- `affiliate_commission.source_type`: `activation_code` or `vip_order`
- `affiliate_commission.status`: `pending`, `settled`, `rejected`, `frozen`, `reversed`
- unique index name `affiliate_commission_source_level_beneficiary_unique` on `source_type`, `source_id`, `level`, `beneficiary_user_id`
- indexes on `buyer_user_id`, `beneficiary_user_id`, `status`, `audit_admin_id`, `settled_ledger_id`
- `user_balance_ledger.direction`: `in`, `out`, `freeze`, `unfreeze`
- `user_balance_ledger.type`: `affiliate_commission`, `admin_adjust`, `withdraw_freeze`, `withdraw_success`, `withdraw_reject`, `reversal`
- indexes on `user_id`, `type`, `source_type/source_id`, `admin_id`, `create_time`

Model rules:

```php
final class AffiliateCommission extends BaseModel
{
    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'audited_at' => 'datetime',
    ];
}

final class UserBalanceLedger extends BaseModel
{
    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'frozen_before' => 'decimal:2',
        'frozen_after' => 'decimal:2',
    ];

    public static function bootSoftDeletes() {}
}
```

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter tables
git add database/migrations/2026_07_05_000005_create_user_affiliate_balance_phase_5_tables.php app/Models/AffiliateCommission.php app/Models/UserBalanceLedger.php tests/Feature/User/UserAffiliateBalanceTest.php
git commit -m "feat: add affiliate balance persistence"
```

## Task 2: Balance Ledger Service

**Files:**
- Create: `app/User/BalanceLedgerService.php`
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`

- [ ] **Step 1: Add failing balance service tests**

Cover:
- credit increases `user_account.available_balance` and writes one `in` ledger row;
- debit decreases available balance and rejects insufficient funds;
- freeze moves amount from available to frozen and writes `freeze`;
- unfreeze moves amount from frozen to available and writes `unfreeze`;
- admin adjustment requires non-empty reason and admin id;
- ledger before/after snapshots match the account row after the transaction.

Example assertion:

```php
$ledger = app(BalanceLedgerService::class)->credit(
    $userId,
    '10.50',
    'affiliate_commission',
    'affiliate_commission',
    123,
    'Commission settlement',
    7
);

$this->assertDatabaseHas('user_account', [
    'id' => $userId,
    'available_balance' => '10.50',
    'frozen_balance' => '0.00',
]);
$this->assertSame('10.50', (string) $ledger['balance_after']);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "balance|ledger|freeze|adjust"
```

Expected: FAIL because `BalanceLedgerService` does not exist.

- [ ] **Step 3: Implement `BalanceLedgerService`**

Public API:

```php
public function credit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark, ?int $adminId = null): array;
public function debit(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark, ?int $adminId = null): array;
public function freeze(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark, ?int $adminId = null): array;
public function unfreeze(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark, ?int $adminId = null): array;
public function adminAdjust(int $userId, string|float $amount, string $reason, int $adminId): array;
public function summary(int $userId): array;
public function ledger(int $userId, int $limit = 20): array;
```

Implementation rules:
- lock `user_account` row with `lockForUpdate()`;
- use two-decimal string arithmetic with `bcadd`/`bcsub` when available, otherwise normalized floats rounded to two decimals;
- reject amounts `<= 0`;
- reject debit/freeze if available balance is insufficient;
- reject unfreeze if frozen balance is insufficient;
- create ledger row inside the same transaction as account snapshot update;
- return arrays, not Eloquent models.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "balance|ledger|freeze|adjust"
git add app/User/BalanceLedgerService.php tests/Feature/User/UserAffiliateBalanceTest.php
git commit -m "feat: add user balance ledger service"
```

## Task 3: Affiliate Commission Service

**Files:**
- Create: `app/User/AffiliateService.php`
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`

- [ ] **Step 1: Add failing affiliate service tests**

Cover:
- commissionable activation-code source creates one level-1 pending commission for `parent_user_id`;
- source creates one level-2 pending commission for `grandparent_user_id`;
- non-commissionable source creates no commissions;
- missing parent/grandparent creates only existing levels;
- duplicate source does not create duplicate commissions;
- disabled/frozen beneficiary users are skipped;
- approving pending commission writes balance ledger and marks commission `settled`;
- rejecting pending commission requires reason and leaves balance unchanged;
- approving already settled or rejected commission fails.

Example chain setup:

```php
[$parent, $child, $buyer] = $this->createInviteChain();
$commissions = app(AffiliateService::class)->createForActivationCode(
    buyerUserId: $buyer->id,
    activationCodeId: $code->id,
    firstLevelReward: '8.00',
    secondLevelReward: '3.00',
    isCommissionable: true
);

$this->assertCount(2, $commissions);
$this->assertDatabaseHas('affiliate_commission', [
    'source_type' => 'activation_code',
    'source_id' => $code->id,
    'buyer_user_id' => $buyer->id,
    'beneficiary_user_id' => $child->id,
    'level' => 1,
    'amount' => '8.00',
    'status' => 'pending',
]);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "affiliate|commission|approve|reject"
```

Expected: FAIL because `AffiliateService` does not exist.

- [ ] **Step 3: Implement `AffiliateService`**

Public API:

```php
public function createForActivationCode(int $buyerUserId, int $activationCodeId, string|float $firstLevelReward, string|float $secondLevelReward, bool $isCommissionable): array;
public function createForVipOrder(int $buyerUserId, int $vipOrderId, string|float $amount, string|float $firstLevelRate, string|float $secondLevelRate, bool $isCommissionable): array;
public function approve(int $commissionId, int $adminId): array;
public function reject(int $commissionId, string $reason, int $adminId): array;
public function reverse(int $commissionId, string $reason, int $adminId): array;
```

Implementation rules:
- read active `user_invite_relation` for buyer;
- generate only two levels: parent gets level 1, grandparent gets level 2;
- skip missing, deleted, disabled, or frozen beneficiary accounts;
- skip zero or negative reward amount;
- use `firstOrCreate` or catch unique violations to enforce idempotency;
- new commissions are `pending`;
- `approve()` locks commission, requires `pending`, credits beneficiary via `BalanceLedgerService`, sets `status = settled`, `audit_admin_id`, `audited_at`, and `settled_ledger_id`;
- `reject()` locks commission, requires `pending`, requires non-empty reason, sets `status = rejected`, `audit_admin_id`, and `audited_at`;
- `reverse()` handles settled commission correction by writing a `reversal` debit if funds exist, marks original `reversed`, and links the reversal commission id when created.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "affiliate|commission|approve|reject"
git add app/User/AffiliateService.php tests/Feature/User/UserAffiliateBalanceTest.php
git commit -m "feat: add affiliate commission service"
```

## Task 4: Activation-Code Commission Integration

**Files:**
- Modify: `app/User/ActivationCodeService.php`
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`
- Modify: `tests/Feature/User/UserVipActivationTest.php`

- [ ] **Step 1: Add failing redemption integration tests**

Cover:
- successful redemption of a commissionable batch creates pending level-1 and level-2 commissions;
- successful redemption of a non-commissionable batch creates no commissions;
- `activation_code_redemption.commission_source_id` is set to the first generated commission id when any commission is created;
- failed redemption creates no commission and leaves `commission_source_id` null;
- existing P4 redemption/VIP tests still pass.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "activation|redemption"
```

Expected: FAIL because redemption does not call `AffiliateService`.

- [ ] **Step 3: Wire `ActivationCodeService`**

Change constructor:

```php
public function __construct(
    private readonly VipService $vip,
    private readonly AffiliateService $affiliate
) {
}
```

After `VipService::grant(...)`, call:

```php
$commissions = $this->affiliate->createForActivationCode(
    buyerUserId: $userId,
    activationCodeId: (int) $code->id,
    firstLevelReward: $batch->first_level_reward,
    secondLevelReward: $batch->second_level_reward,
    isCommissionable: (bool) $batch->is_commissionable
);
$commissionSourceId = $commissions[0]['id'] ?? null;
```

Pass `$commissionSourceId` into `writeRedemption(...)`.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "activation|redemption"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php
git add app/User/ActivationCodeService.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserVipActivationTest.php
git commit -m "feat: create commissions from activation redemption"
```

## Task 5: User Balance Endpoints

**Files:**
- Create: `app/Http/Controllers/user/BalanceController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`

- [ ] **Step 1: Add failing endpoint tests**

Cover:
- `GET /user/balance` requires session user and returns available/frozen balances;
- `GET /user/balance/ledger` requires session user and returns newest ledger rows;
- unauthenticated requests return `code = 0`;
- endpoints are under existing `user` prefix and keep `CheckInstall` plus throttle middleware.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "endpoint|route"
```

Expected: FAIL because routes/controllers do not exist.

- [ ] **Step 3: Implement controller and routes**

Add routes:

```php
Route::get('/balance', [\App\Http\Controllers\user\BalanceController::class, 'summary']);
Route::get('/balance/ledger', [\App\Http\Controllers\user\BalanceController::class, 'ledger']);
```

Controller rules:
- read `session('user.id')`;
- return explicit JSON, not `JumpTrait`;
- validate `limit` to `1..100`, default `20`;
- use `BalanceLedgerService::summary()` and `BalanceLedgerService::ledger()`.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php --filter "endpoint|route"
git add app/Http/Controllers/user/BalanceController.php routes/web.php tests/Feature/User/UserAffiliateBalanceTest.php
git commit -m "feat: add user balance endpoints"
```

## Task 6: Admin Commission and Balance Management

**Files:**
- Create: `app/Http/Controllers/admin/user/CommissionController.php`
- Create: `app/Http/Controllers/admin/user/BalanceController.php`
- Create: `resources/views/admin/user/commission/index.blade.php`
- Create: `resources/views/admin/user/balance/index.blade.php`
- Create: `public/static/admin/js/user/commission.js`
- Create: `public/static/admin/js/user/balance.js`
- Create: `tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php`

- [ ] **Step 1: Add failing admin tests**

Cover:
- `/admin/user/commission/index` lists safe fields and supports allowlisted search/sort;
- commission list does not allow arbitrary columns from request conditions;
- `/admin/user/commission/approve` requires admin session id and settles pending commission;
- `/admin/user/commission/reject` requires non-empty reason and marks pending commission rejected;
- `/admin/user/balance/index` lists ledger rows with safe fields;
- `/admin/user/balance/adjust` requires user id, amount, reason, and admin id;
- inherited delete/recycle/export behavior is blocked where unsafe.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAffiliateBalanceControllerTest.php
```

Expected: FAIL with missing admin controllers/assets.

- [ ] **Step 3: Implement admin controllers and assets**

Commission controller:
- list columns: `id`, `source_type`, `source_id`, `buyer_user_id`, `beneficiary_user_id`, `level`, `amount`, `status`, `reason`, `audit_admin_id`, `audited_at`, `settled_ledger_id`, `create_time`
- searchable columns: `id`, `source_type`, `source_id`, `buyer_user_id`, `beneficiary_user_id`, `level`, `status`, `audit_admin_id`, `create_time`
- `approve()` calls `AffiliateService::approve((int) request('id'), (int) session('admin.id'))`
- `reject()` calls `AffiliateService::reject((int) request('id'), (string) request('reason'), (int) session('admin.id'))`

Balance controller:
- list columns: `id`, `user_id`, `direction`, `amount`, `balance_before`, `balance_after`, `frozen_before`, `frozen_after`, `type`, `source_type`, `source_id`, `remark`, `admin_id`, `create_time`
- searchable columns: `id`, `user_id`, `direction`, `type`, `source_type`, `source_id`, `admin_id`, `create_time`
- `adjust()` calls `BalanceLedgerService::adminAdjust((int) request('user_id'), request('amount'), (string) request('reason'), (int) session('admin.id'))`

Assets:
- build Layui table shells for list, approve/reject, and admin adjustment actions;
- no hidden feature text or frontend marketing copy.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAffiliateBalanceControllerTest.php
node --check public\static\admin\js\user\commission.js
node --check public\static\admin\js\user\balance.js
git add app/Http/Controllers/admin/user/CommissionController.php app/Http/Controllers/admin/user/BalanceController.php resources/views/admin/user/commission/index.blade.php resources/views/admin/user/balance/index.blade.php public/static/admin/js/user/commission.js public/static/admin/js/user/balance.js tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php
git commit -m "feat: add admin affiliate balance management"
```

## Task 7: Review and Full Verification

- [ ] **Step 1: Focused tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAffiliateBalanceTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAffiliateBalanceControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserVipActivationTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
```

- [ ] **Step 2: Full suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Lint/static**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\BalanceLedgerService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\AffiliateService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\ActivationCodeService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\BalanceController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\CommissionController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\BalanceController.php
node --check public\static\admin\js\user\commission.js
node --check public\static\admin\js\user\balance.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- activation-code redemption creates commissions only for commissionable batches;
- two-level relation uses `parent_user_id` and `grandparent_user_id`, never deeper paths;
- duplicate redemption/source paths cannot create duplicate commission rows;
- new commission rows default to `pending`;
- admin approval is the only path that credits user balance;
- admin rejection requires reason and does not create ledger rows;
- every balance snapshot mutation has exactly one ledger row in the same DB transaction;
- admin adjustment always records reason and admin id;
- list/search/order allowlists prevent exposing unsafe columns;
- no third-party review dependency was added.

- [ ] **Step 5: Review commit**

If review changes code, commit those changes. If no code changes are needed, use an empty checkpoint commit:

```powershell
git commit --allow-empty -m "chore: review user affiliate balance phase 5"
```

## Plan Self-Review

- Spec coverage: This plan covers Phase 5 commission source marking, activation-code two-level commission generation, admin commission review, settlement into balance ledger, user balance APIs, and admin balance adjustment.
- Placeholder scan: No task uses deferred placeholder wording; each task has exact files, commands, expected failures, service APIs, and commit points.
- Type consistency: Table names, model names, route paths, statuses, service method signatures, and ledger directions match across tasks.
- Scope guard: VIP order payment integration, withdrawals, batch review, and reporting are held for Phase 6 or later.
