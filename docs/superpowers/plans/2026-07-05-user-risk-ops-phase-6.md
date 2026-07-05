# User Risk Ops Phase 6 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add long-running operations controls for user risk events, batch commission review, safe activation-code export, affiliate statistics, and withdrawal audit entry points.

**Architecture:** Keep risk detection in `App\User\RiskService`, commission operations in `AffiliateService`, withdrawal balance movement in `WithdrawalService`, and exports/statistics behind admin controllers with strict safe-field allowlists. Do not store or export activation-code plaintext after generation; Phase 6 export returns safe metadata only. All balance-affecting withdrawal operations go through `BalanceLedgerService` and write ledger rows in the same transaction.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin dynamic admin controllers, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `user_risk_event` table and model.
- `user_withdrawal_request` table and model.
- `RiskService` for invite-burst and activation-failure events.
- `WithdrawalService` for user withdrawal request, admin approval, and admin rejection.
- `BalanceLedgerService::settleFrozen(...)` for successful payout from frozen balance.
- Activation-code safe export that exposes tails/status/source metadata but never `code_hash` or full plaintext codes.
- Commission batch approve/reject endpoints.
- Commission statistics endpoint.
- Admin risk-event and withdrawal management surfaces.
- Focused tests, full-suite verification, and review checkpoint commit.

Excluded:
- Real payment gateway or bank transfer integration.
- Automatic third-party review.
- Exporting historical full activation-code plaintext. The system cannot safely do this because plaintext is intentionally never stored.
- Device fingerprinting. Phase 6 uses available account/IP/source data only.

---

## File Structure

- Create `database/migrations/2026_07_05_000006_create_user_risk_ops_phase_6_tables.php`
- Create `app/Models/UserRiskEvent.php`
- Create `app/Models/UserWithdrawalRequest.php`
- Create `app/User/RiskService.php`
- Create `app/User/WithdrawalService.php`
- Modify `app/User/UserAuthService.php`
- Modify `app/User/ActivationCodeService.php`
- Modify `app/User/AffiliateService.php`
- Modify `app/User/BalanceLedgerService.php`
- Modify `app/Http/Controllers/admin/user/ActivationCodeController.php`
- Modify `app/Http/Controllers/admin/user/CommissionController.php`
- Create `app/Http/Controllers/admin/user/RiskEventController.php`
- Create `app/Http/Controllers/admin/user/WithdrawalController.php`
- Create `app/Http/Controllers/user/WithdrawalController.php`
- Modify `routes/web.php`
- Create `resources/views/admin/user/risk-event/index.blade.php`
- Create `resources/views/admin/user/withdrawal/index.blade.php`
- Create `public/static/admin/js/user/risk-event.js`
- Create `public/static/admin/js/user/withdrawal.js`
- Modify `public/static/admin/js/user/activation-code.js`
- Modify `public/static/admin/js/user/commission.js`
- Create `tests/Feature/User/UserRiskOpsTest.php`
- Create `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

---

## Task 1: Risk and Withdrawal Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000006_create_user_risk_ops_phase_6_tables.php`
- Create: `app/Models/UserRiskEvent.php`
- Create: `app/Models/UserWithdrawalRequest.php`
- Test: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Write failing persistence test**

Assert tables and fields:

```php
$this->assertTrue(Schema::hasColumns('user_risk_event', [
    'id', 'user_id', 'category', 'event_type', 'severity', 'source_type',
    'source_id', 'ip', 'status', 'detail_json', 'review_admin_id',
    'reviewed_at', 'create_time', 'update_time',
]));
$this->assertTrue(Schema::hasColumns('user_withdrawal_request', [
    'id', 'withdrawal_no', 'user_id', 'amount', 'status', 'request_ip',
    'account_snapshot_json', 'ledger_freeze_id', 'ledger_success_id',
    'reason', 'audit_admin_id', 'audited_at', 'create_time', 'update_time',
]));
```

Assert model casts:

```php
$event = UserRiskEvent::query()->create([
    'user_id' => 1,
    'category' => 'invite',
    'event_type' => 'invite_burst',
    'severity' => 'medium',
    'source_type' => 'user_invite_relation',
    'source_id' => 2,
    'ip' => '127.0.0.1',
    'status' => 'open',
    'detail_json' => ['count' => 10],
    'create_time' => time(),
    'update_time' => time(),
]);
$this->assertSame(['count' => 10], $event->refresh()->detail_json);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter tables
```

Expected: FAIL because Phase 6 tables/models do not exist.

- [ ] **Step 3: Implement migration and models**

Migration rules:
- `user_risk_event.status`: `open`, `reviewed`, `ignored`
- `user_risk_event.severity`: `low`, `medium`, `high`
- indexes: `user_id`, `category/event_type`, `status`, `source_type/source_id`, `ip`, `create_time`
- `user_withdrawal_request.status`: `pending`, `approved`, `rejected`, `paid`, `cancelled`
- unique `withdrawal_no`
- indexes: `user_id`, `status`, `audit_admin_id`, `create_time`
- `account_snapshot_json` stores account/payment destination snapshot from request payload.

Model rules:

```php
final class UserRiskEvent extends BaseModel
{
    protected $table = 'user_risk_event';
    protected $guarded = [];
    public static function bootSoftDeletes() {}
    protected $casts = [
        'detail_json' => 'array',
        'reviewed_at' => 'datetime',
    ];
}

final class UserWithdrawalRequest extends BaseModel
{
    protected $table = 'user_withdrawal_request';
    protected $guarded = [];
    public static function bootSoftDeletes() {}
    protected $casts = [
        'amount' => 'decimal:2',
        'account_snapshot_json' => 'array',
        'audited_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter tables
git add database/migrations/2026_07_05_000006_create_user_risk_ops_phase_6_tables.php app/Models/UserRiskEvent.php app/Models/UserWithdrawalRequest.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: add user risk ops persistence"
```

## Task 2: Risk Service and Event Integration

**Files:**
- Create: `app/User/RiskService.php`
- Modify: `app/User/UserAuthService.php`
- Modify: `app/User/ActivationCodeService.php`
- Modify: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Add failing risk tests**

Cover:
- a parent with 5 invited registrations from the same IP in 24 hours creates one `invite_burst` event;
- repeated evaluation for the same relation does not duplicate the open event;
- failed activation-code redemption creates `activation_code_failed` event with source tail omitted and no code hash/plaintext;
- admin/user normal successful actions do not create high-severity events by default.

Example assertions:

```php
$events = app(RiskService::class)->evaluateInviteRegistration($buyer->id);
$this->assertCount(1, $events);
$this->assertDatabaseHas('user_risk_event', [
    'user_id' => $buyer->id,
    'category' => 'invite',
    'event_type' => 'invite_burst',
    'severity' => 'medium',
    'status' => 'open',
]);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "risk|invite|activation"
```

Expected: FAIL because `RiskService` and integrations do not exist.

- [ ] **Step 3: Implement `RiskService`**

Public API:

```php
public function evaluateInviteRegistration(int $userId): array;
public function recordActivationFailure(int $userId, string $ip, string $reason): array;
public function review(int $eventId, string $status, int $adminId): array;
```

Implementation rules:
- `evaluateInviteRegistration` reads active `user_invite_relation` for `user_id`;
- count sibling invited users with same `parent_user_id` and same `register_ip` in the last 24 hours;
- threshold is `config('user.risk.invite_burst_threshold', 5)`;
- create one open event per `source_type=user_invite_relation` and `source_id=relation.id` and `event_type=invite_burst`;
- `recordActivationFailure` creates a low-severity event unless the same user has 5 failures from the same IP in 10 minutes, then severity is `medium`;
- event detail never stores submitted activation code plaintext or `code_hash`;
- integrations:
  - call `RiskService::evaluateInviteRegistration($user->id)` after successful invite binding in `UserAuthService::register`;
  - call `RiskService::recordActivationFailure($userId, $ip, $error)` when `ActivationCodeService::redeem` fails.

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "risk|invite|activation"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
git add app/User/RiskService.php app/User/UserAuthService.php app/User/ActivationCodeService.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: add user risk event service"
```

## Task 3: Withdrawal Service and User Endpoints

**Files:**
- Modify: `app/User/BalanceLedgerService.php`
- Create: `app/User/WithdrawalService.php`
- Create: `app/Http/Controllers/user/WithdrawalController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Add failing withdrawal tests**

Cover:
- user can request withdrawal when available balance is sufficient;
- request freezes available balance and writes `withdraw_freeze` ledger;
- request stores account snapshot and request IP;
- blank/zero/negative amount fails;
- request above available balance fails;
- `GET /user/withdrawal` lists current user's requests;
- unauthenticated withdrawal endpoints return `code = 0`.

Example:

```php
$request = app(WithdrawalService::class)->request($user->id, '10.00', [
    'account_name' => 'Alice',
    'account_no' => 'masked-001',
], '127.0.0.1');

$this->assertSame('pending', $request['status']);
$this->assertDatabaseHas('user_account', [
    'id' => $user->id,
    'available_balance' => '90.00',
    'frozen_balance' => '10.00',
]);
```

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "withdrawal|settleFrozen"
```

Expected: FAIL because withdrawal service/routes do not exist.

- [ ] **Step 3: Implement withdrawal service and frozen settlement**

Add `BalanceLedgerService` method:

```php
public function settleFrozen(int $userId, string|float $amount, string $type, ?string $sourceType, ?int $sourceId, string $remark, ?int $adminId = null): array;
```

Rules:
- `settleFrozen` decreases `frozen_balance` only and writes ledger row with `direction = out`;
- reject if frozen balance is insufficient.

`WithdrawalService` public API:

```php
public function request(int $userId, string|float $amount, array $accountSnapshot, string $ip): array;
public function approve(int $withdrawalId, int $adminId): array;
public function reject(int $withdrawalId, string $reason, int $adminId): array;
public function listForUser(int $userId, int $limit = 20): array;
```

Rules:
- request status starts `pending`;
- request freezes balance with type `withdraw_freeze`;
- approve requires `pending`, calls `settleFrozen(..., 'withdraw_success', 'user_withdrawal_request', $id, ...)`, then marks `paid`;
- reject requires `pending`, calls `unfreeze(..., 'withdraw_reject', 'user_withdrawal_request', $id, ...)`, then marks `rejected`;
- admin id and reject reason are required for review.

User routes:

```php
Route::post('/withdrawal/request', [\App\Http\Controllers\user\WithdrawalController::class, 'request']);
Route::get('/withdrawal', [\App\Http\Controllers\user\WithdrawalController::class, 'index']);
```

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "withdrawal|settleFrozen"
git add app/User/BalanceLedgerService.php app/User/WithdrawalService.php app/Http/Controllers/user/WithdrawalController.php routes/web.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: add user withdrawal audit flow"
```

## Task 4: Admin Batch Review, Stats, Risk, Withdrawal, and Export

**Files:**
- Modify: `app/User/AffiliateService.php`
- Modify: `app/Http/Controllers/admin/user/CommissionController.php`
- Modify: `app/Http/Controllers/admin/user/ActivationCodeController.php`
- Create: `app/Http/Controllers/admin/user/RiskEventController.php`
- Create: `app/Http/Controllers/admin/user/WithdrawalController.php`
- Create: `resources/views/admin/user/risk-event/index.blade.php`
- Create: `resources/views/admin/user/withdrawal/index.blade.php`
- Create: `public/static/admin/js/user/risk-event.js`
- Create: `public/static/admin/js/user/withdrawal.js`
- Modify: `public/static/admin/js/user/activation-code.js`
- Modify: `public/static/admin/js/user/commission.js`
- Test: `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

- [ ] **Step 1: Add failing admin ops tests**

Cover:
- `/admin/user/commission/batchApprove` settles multiple pending commissions;
- `/admin/user/commission/batchReject` rejects multiple pending commissions and requires reason;
- `/admin/user/commission/stats` returns counts and amounts by status;
- `/admin/user/activation-code/export` returns safe metadata and never includes `code_hash` or full plaintext code;
- `/admin/user/risk-event/index` lists safe risk event fields;
- `/admin/user/risk-event/review` marks open event as reviewed/ignored with admin id;
- `/admin/user/withdrawal/index` lists withdrawal rows;
- `/admin/user/withdrawal/approve` marks pending withdrawal paid and writes success ledger;
- `/admin/user/withdrawal/reject` marks pending withdrawal rejected and unfreezes balance;
- unsafe inherited write/delete/export actions remain blocked.

- [ ] **Step 2: Verify RED**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php
```

Expected: FAIL because controllers/actions are missing.

- [ ] **Step 3: Implement admin services and controllers**

Add `AffiliateService` methods:

```php
public function batchApprove(array $commissionIds, int $adminId): array;
public function batchReject(array $commissionIds, string $reason, int $adminId): array;
public function stats(): array;
```

Rules:
- batch methods skip non-pending rows by returning per-id errors, never partial-crash the whole request;
- stats returns:
  - `by_status`: count and amount for `pending`, `settled`, `rejected`, `frozen`, `reversed`
  - `top_beneficiaries`: top 10 beneficiary totals from settled commissions.

Activation export rules:
- return JSON with `id`, `batch_id`, `display_code_tail`, `status`, `max_uses`, `used_count`, `bound_user_id`, `expires_at`, `create_time`;
- do not call historical plaintext export;
- do not include `code_hash`.

Admin controller allowlists:
- risk list columns: `id`, `user_id`, `category`, `event_type`, `severity`, `source_type`, `source_id`, `ip`, `status`, `review_admin_id`, `reviewed_at`, `create_time`
- withdrawal list columns: `id`, `withdrawal_no`, `user_id`, `amount`, `status`, `request_ip`, `ledger_freeze_id`, `ledger_success_id`, `reason`, `audit_admin_id`, `audited_at`, `create_time`

- [ ] **Step 4: Verify GREEN and commit**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php
node --check public\static\admin\js\user\risk-event.js
node --check public\static\admin\js\user\withdrawal.js
node --check public\static\admin\js\user\activation-code.js
node --check public\static\admin\js\user\commission.js
git add app/User/AffiliateService.php app/Http/Controllers/admin/user/CommissionController.php app/Http/Controllers/admin/user/ActivationCodeController.php app/Http/Controllers/admin/user/RiskEventController.php app/Http/Controllers/admin/user/WithdrawalController.php resources/views/admin/user/risk-event/index.blade.php resources/views/admin/user/withdrawal/index.blade.php public/static/admin/js/user/risk-event.js public/static/admin/js/user/withdrawal.js public/static/admin/js/user/activation-code.js public/static/admin/js/user/commission.js tests/Feature/User/UserAdminRiskOpsControllerTest.php
git commit -m "feat: add admin user risk ops management"
```

## Task 5: Review and Full Verification

- [ ] **Step 1: Focused tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php
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
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\RiskService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\BalanceLedgerService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\AffiliateService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\ActivationCodeService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserAuthService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\user\WithdrawalController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\RiskEventController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\WithdrawalController.php
node --check public\static\admin\js\user\risk-event.js
node --check public\static\admin\js\user\withdrawal.js
node --check public\static\admin\js\user\activation-code.js
node --check public\static\admin\js\user\commission.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- risk events never store activation-code plaintext or code hash;
- invite burst detection is idempotent per relation;
- withdrawal request freezes balance before creating an auditable pending row;
- withdrawal approval consumes frozen balance and writes `withdraw_success`;
- withdrawal rejection unfreezes and writes `withdraw_reject`;
- activation export never includes `code_hash` or full plaintext code;
- batch commission review reports per-id results and does not auto-settle outside admin action;
- stats queries cannot expose unsafe columns;
- all new admin list endpoints sanitize filters and sorting;
- no third-party review flow was added.

- [ ] **Step 5: Review commit**

If review changes code, commit those changes. If no code changes are needed, use an empty checkpoint commit:

```powershell
git commit --allow-empty -m "chore: review user risk ops phase 6"
```

## Plan Self-Review

- Spec coverage: This plan covers Phase 6 abnormal invite detection, batch commission review, activation-code safe export, affiliate stats, withdrawal audit entry, admin operations, and full verification.
- Placeholder scan: No task uses deferred placeholder wording; each task has exact files, commands, expected failures, APIs, rules, and commit points.
- Type consistency: Table names, statuses, route paths, service names, and ledger types match the previous P1-P5 architecture.
- Scope guard: Real payout integrations, device fingerprinting, and plaintext activation-code export remain intentionally out of scope.
