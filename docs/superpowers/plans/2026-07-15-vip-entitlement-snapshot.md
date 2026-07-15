# VIP Entitlement Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make VIP grants snapshot-based, expiry-aware, and idempotent.

**Architecture:** Keep Eloquent models thin and enforce the entitlement state transition in `VipService`. Activation-code batches own immutable duration and level snapshots; `ActivationCodeService` supplies them to the grant operation.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, SQLite/MySQL migrations, PHPUnit 12.

## Global Constraints

- Preserve existing HTTP response shapes.
- Preserve source compatibility for existing `VipService::grant()` callers.
- Use database transactions and row locks for entitlement mutation.
- Do not modify unrelated user, module, or UI behavior.

---

### Task 1: Define Snapshot and Idempotency Behavior

**Files:**
- Modify: `tests/Feature/User/UserVipActivationTest.php`

**Interfaces:**
- Consumes: `VipService::grant(int, int, string, int, ?int, ?int): array`
- Produces: regression coverage for expiry-aware levels, snapshots, and source idempotency.

- [ ] **Step 1: Write failing tests**

Add tests equivalent to:

```php
public function test_expired_higher_level_does_not_leak_into_new_grant(): void
{
    $user = $this->registerUser('expired-tier@example.com');
    $premium = $this->createVipPlan('Premium', 3, 1);
    $basic = $this->createVipPlan('Basic', 1, 30);
    app(VipService::class)->grant($user['user']['id'], $premium->id, 'activation_code', 9001);
    Carbon::setTestNow(Carbon::now()->addDays(2));

    $grant = app(VipService::class)->grant($user['user']['id'], $basic->id, 'activation_code', 9002);

    $this->assertSame(1, $grant['vip_level']);
}

public function test_duplicate_source_grant_is_idempotent(): void
{
    $user = $this->registerUser('idempotent-vip@example.com');
    $plan = $this->createVipPlan('Monthly', 1, 30);
    $first = app(VipService::class)->grant($user['user']['id'], $plan->id, 'vip_order', 7001);
    $second = app(VipService::class)->grant($user['user']['id'], $plan->id, 'vip_order', 7001);

    $this->assertSame($first['vip_record_id'], $second['vip_record_id']);
    $this->assertSame(1, UserVipRecord::query()->count());
}
```

Add an activation redemption test that creates a 7-day, level-2 batch from a 30-day plan, edits the plan to level 5 and 365 days, then verifies the grant still lasts 7 days at level 2. Assert both batch and VIP record snapshots.

- [ ] **Step 2: Run the focused test file**

Run:

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/User/UserVipActivationTest.php
```

Expected: new assertions fail because `vip_level` snapshot storage and snapshot-aware grant arguments do not exist.

### Task 2: Add Snapshot Schema

**Files:**
- Create: `database/migrations/2026_07_15_000001_harden_vip_entitlement_snapshots.php`
- Modify: `app/Models/ActivationCodeBatch.php`

**Interfaces:**
- Produces: `activation_code_batch.vip_level`, `user_vip_record.vip_level`, and unique `user_vip_record(source_type, source_id)`.

- [ ] **Step 1: Add the migration**

Add `vip_level` to both snapshot tables, backfill each from `vip_plan`, validate duplicate VIP sources, and add the unique source index. Use `Schema::hasColumn()` guards and row-by-row portable backfills so SQLite and MySQL follow the same path.

- [ ] **Step 2: Add the model cast**

Cast `vip_level` and `duration_days` to integers on `ActivationCodeBatch`; cast `vip_level` and `duration_days` on `UserVipRecord`.

- [ ] **Step 3: Run the focused test file**

Expected: schema assertions pass while behavior tests still fail.

### Task 3: Implement Snapshot-Aware Grants

**Files:**
- Modify: `app/User/VipService.php`
- Modify: `app/User/ActivationCodeService.php`

**Interfaces:**
- Produces: `VipService::grant(..., ?int $durationDays = null, ?int $vipLevel = null): array`.

- [ ] **Step 1: Implement idempotent source lookup**

After locking the user, query `UserVipRecord` by `source_type` and `source_id` with `lockForUpdate()`. Return the existing record when it belongs to the same user; reject a cross-user source collision.

- [ ] **Step 2: Implement expiry-aware level selection**

Resolve `$grantDurationDays` and `$grantVipLevel` from explicit snapshots or the plan, require both to be positive, and calculate:

```php
$hasActiveVip = $beforeExpiresAt !== null && $beforeExpiresAt->greaterThan($now);
$startsAt = $hasActiveVip ? $beforeExpiresAt->copy() : $now->copy();
$afterExpiresAt = $startsAt->copy()->addDays($grantDurationDays);
$effectiveVipLevel = $hasActiveVip
    ? max((int) $user->vip_level, $grantVipLevel)
    : $grantVipLevel;
```

- [ ] **Step 3: Pass batch snapshots during redemption**

Capture `vip_level` at batch creation, expose it in `publicBatch()`, and call:

```php
$this->vip->grant(
    $userId,
    (int) $batch->vip_plan_id,
    'activation_code',
    (int) $code->id,
    (int) $batch->duration_days,
    (int) $batch->vip_level,
);
```

- [ ] **Step 4: Run focused and related tests**

Run the VIP activation, affiliate balance, Qingyu module, and admin activation test files. Expected: all pass.

### Task 4: Review and Commit

**Files:**
- Review all files changed by Tasks 1-3.

- [ ] **Step 1: Run formatting and complete test suite**

Run Pint on changed PHP files, then PHPUnit. Expected: zero failures.

- [ ] **Step 2: Inspect migration compatibility and diff**

Verify SQLite and MySQL paths, response compatibility, no secrets, and no unrelated changes.

- [ ] **Step 3: Commit**

```bash
git add app/User app/Models database/migrations tests/Feature/User docs/superpowers
git commit -m "fix: harden VIP entitlement snapshots"
```
