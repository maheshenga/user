# P20 Admin Ops Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This session executes inline without subagents per user instruction.

**Goal:** Localize admin operations errors for commission review, withdrawal review/payout, and risk event review so the operations backend no longer exposes English validation messages in these flows.

**Architecture:** Keep service method signatures, controller response shapes, database status values, ledger side effects, payout idempotency, and risk review persistence unchanged. Replace only user-visible exception strings and update feature tests that assert those errors through service and admin controller paths.

**Tech Stack:** PHP 8.3, Laravel feature tests, SQLite test runner, existing `AffiliateService`, `WithdrawalService`, `RiskService`, and admin/user feature suites.

## Global Constraints

- Do not change stored enum/status values such as `pending`, `settled`, `rejected`, `approved`, `paid`, `payout_failed`, `reviewed`, or `ignored`.
- Do not change balance mutation, commission settlement, payout settlement, payout idempotency, or risk review persistence behavior.
- Do not change API response shape.
- Keep module-container review wording for a later P; this P is only user-ops/admin-ops services.
- Execute directly in this session; do not dispatch subagents.

---

### Task 1: RED tests for affiliate commission admin errors

**Files:**
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`
- Modify: `tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php`
- Modify: `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

**Interfaces:**
- Consumes: `App\User\AffiliateService::approve(int $commissionId, int $adminId): array`
- Consumes: `App\User\AffiliateService::reject(int $commissionId, string $reason, int $adminId): array`
- Consumes: `POST /admin/user/commission/reject`
- Consumes: `POST /admin/user/commission/batchReject`
- Produces: failing expectations for Chinese commission review messages.

- [ ] **Step 1: Update service blank reject reason expectation**

In `UserAffiliateBalanceTest::test_affiliate_service_rejects_pending_commission_without_balance_change`, replace:

```php
$this->assertSame('Reject reason is required.', $exception->getMessage());
```

with:

```php
$this->assertSame('拒绝原因不能为空。', $exception->getMessage());
```

- [ ] **Step 2: Update non-pending approve expectation**

In `UserAffiliateBalanceTest::test_affiliate_service_rejects_review_of_non_pending_commission`, replace:

```php
$this->expectExceptionMessage('Only pending commission can be approved.');
```

with:

```php
$this->expectExceptionMessage('只有待审核佣金可以审核通过。');
```

- [ ] **Step 3: Add commission review edge-case assertions**

Add to `UserAffiliateBalanceTest`:

```php
public function test_affiliate_service_returns_chinese_admin_review_errors(): void
{
    [, , $buyer] = $this->createInviteChain('review-errors');
    app(AffiliateService::class)->createForActivationCode($buyer->id, 1201, '8.00', '0.00', true);
    $commission = AffiliateCommission::query()->where('level', 1)->firstOrFail();

    foreach ([
        fn () => app(AffiliateService::class)->approve($commission->id, 0) => '管理员 ID 不能为空。',
        fn () => app(AffiliateService::class)->approve(999999, 77) => '佣金记录不存在。',
        fn () => app(AffiliateService::class)->reverse($commission->id, ' ', 77) => '冲正原因不能为空。',
    ] as $operation => $message) {
        try {
            $operation();
            $this->fail("Expected commission review error: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
```

When applying, use an array of `[callable, message]` pairs because PHP closures cannot be array keys.

- [ ] **Step 4: Assert admin controller propagates Chinese messages**

In `UserAdminAffiliateBalanceControllerTest::test_admin_commission_approve_and_reject_use_admin_session`, extend the blank reject response with:

```php
->assertJsonPath('msg', '拒绝原因不能为空。');
```

In `UserAdminRiskOpsControllerTest::test_admin_commission_batch_review_and_stats`, extend the blank batch reject response with:

```php
->assertJsonPath('msg', '拒绝原因不能为空。');
```

- [ ] **Step 5: Run RED for affiliate tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAffiliateBalanceTest|UserAdminAffiliateBalanceControllerTest|UserAdminRiskOpsControllerTest"
```

Expected result: FAIL because affiliate service still returns English review errors.

### Task 2: RED tests for withdrawal and risk admin errors

**Files:**
- Modify: `tests/Feature/User/UserRiskOpsTest.php`
- Modify: `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

**Interfaces:**
- Consumes: `App\User\WithdrawalService`
- Consumes: `App\User\RiskService`
- Consumes: `POST /admin/user/withdrawal/payout`
- Consumes: `POST /admin/user/risk-event/review`
- Produces: failing expectations for Chinese withdrawal payout/review and risk review messages.

- [ ] **Step 1: Update withdrawal service blank reject and duplicate payout expectations**

In `UserRiskOpsTest`, replace:

```php
$this->assertSame('Reject reason is required.', $exception->getMessage());
$this->assertSame('Payout transaction id has already been used.', $exception->getMessage());
```

with:

```php
$this->assertSame('拒绝原因不能为空。', $exception->getMessage());
$this->assertSame('打款流水号已被使用。', $exception->getMessage());
```

- [ ] **Step 2: Add withdrawal payout validation test**

Add to `UserRiskOpsTest`:

```php
public function test_withdrawal_service_returns_chinese_admin_payout_errors(): void
{
    $user = $this->createAccount('withdraw-admin-errors@example.com', '50.00');
    $service = app(WithdrawalService::class);
    $request = $service->request($user->id, '10.00', ['account_no' => 'errors'], '127.0.0.6');

    foreach ([
        [fn () => $service->approve($request['id'], 0), '管理员 ID 不能为空。'],
        [fn () => $service->markPaid($request['id'], ['method' => '', 'transaction_id' => 'TX-1'], 8), '打款方式不能为空。'],
    ] as [$operation, $message]) {
        try {
            $operation();
            $this->fail("Expected withdrawal admin error: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    $service->approve($request['id'], 7);

    foreach ([
        [fn () => $service->markPaid($request['id'], ['method' => 'manual_bank', 'transaction_id' => ''], 8), '打款流水号不能为空。'],
        [fn () => $service->markPayoutFailed($request['id'], ' ', 8), '打款失败原因不能为空。'],
    ] as [$operation, $message]) {
        try {
            $operation();
            $this->fail("Expected withdrawal payout error: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
```

- [ ] **Step 3: Add risk review validation test**

Add to `UserRiskOpsTest`:

```php
public function test_risk_service_returns_chinese_review_errors(): void
{
    $event = UserRiskEvent::query()->create([
        'user_id' => 1,
        'category' => 'invite',
        'event_type' => 'invite_burst',
        'severity' => 'medium',
        'ip' => '127.0.0.1',
        'status' => 'open',
        'create_time' => time(),
        'update_time' => time(),
    ]);

    $service = app(RiskService::class);

    foreach ([
        [fn () => $service->review($event->id, 'closed', 77), '风控事件状态无效。'],
        [fn () => $service->review($event->id, 'ignored', 0), '管理员 ID 不能为空。'],
        [fn () => $service->review(999999, 'ignored', 77), '风控事件不存在。'],
    ] as [$operation, $message]) {
        try {
            $operation();
            $this->fail("Expected risk review error: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
```

- [ ] **Step 4: Assert admin controllers propagate Chinese messages**

In `UserAdminRiskOpsControllerTest::test_admin_withdrawal_index_approve_and_reject`, add after the successful paid response:

```php
$duplicatePaid = $this->postJson('/admin/user/withdrawal/payout', [
    'id' => $approve['id'],
    'method' => 'manual_bank',
    'transaction_id' => 'BANK-ADMIN-001',
]);
$duplicatePaid->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '只有已通过或打款失败的提现可以确认打款。');
```

In `UserAdminRiskOpsControllerTest::test_admin_risk_event_index_and_review`, add before the valid review:

```php
$invalidReview = $this->postJson('/admin/user/risk-event/review', [
    'id' => $event->id,
    'status' => 'closed',
]);
$invalidReview->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '风控事件状态无效。');
```

- [ ] **Step 5: Run RED for withdrawal/risk tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserRiskOpsTest|UserAdminRiskOpsControllerTest"
```

Expected result: FAIL because withdrawal and risk services still return English review/payout errors.

### Task 3: GREEN implementation

**Files:**
- Modify: `app/User/AffiliateService.php`
- Modify: `app/User/WithdrawalService.php`
- Modify: `app/User/RiskService.php`

**Interfaces:**
- Keeps all public method signatures unchanged.
- Produces Chinese `InvalidArgumentException` messages for admin review, payout, and risk review flows.

- [ ] **Step 1: Replace `AffiliateService` messages**

Use these exact replacements:

```text
Admin id is required. => 管理员 ID 不能为空。
Commission not found. => 佣金记录不存在。
Only pending commission can be approved. => 只有待审核佣金可以审核通过。
Reject reason is required. => 拒绝原因不能为空。
Only pending commission can be rejected. => 只有待审核佣金可以拒绝。
Reverse reason is required. => 冲正原因不能为空。
Only settled commission can be reversed. => 只有已结算佣金可以冲正。
```

- [ ] **Step 2: Replace admin `WithdrawalService` messages**

Use these exact replacements:

```text
Admin id is required. => 管理员 ID 不能为空。
Only pending withdrawal can be approved. => 只有待审核提现可以审核通过。
Payout method is required. => 打款方式不能为空。
Payout transaction id is required. => 打款流水号不能为空。
Only approved or failed payout withdrawal can be marked paid. => 只有已通过或打款失败的提现可以确认打款。
Withdrawal payout has already been settled. => 提现打款已结算。
Payout error is required. => 打款失败原因不能为空。
Only approved or failed payout withdrawal can be marked failed. => 只有已通过或打款失败的提现可以标记打款失败。
Paid withdrawal cannot be marked failed. => 已打款提现不能标记失败。
Reject reason is required. => 拒绝原因不能为空。
Only pending, approved, or failed payout withdrawal can be rejected. => 只有待审核、已通过或打款失败的提现可以拒绝。
Payout transaction id has already been used. => 打款流水号已被使用。
```

- [ ] **Step 3: Replace `RiskService` messages**

Use these exact replacements:

```text
Risk event status is invalid. => 风控事件状态无效。
Admin id is required. => 管理员 ID 不能为空。
Risk event not found. => 风控事件不存在。
```

- [ ] **Step 4: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAffiliateBalanceTest|UserAdminAffiliateBalanceControllerTest|UserRiskOpsTest|UserAdminRiskOpsControllerTest"
```

Expected result: PASS.

### Task 4: Verification, review, commit, push

**Files:**
- Review all P20 changes.

**Interfaces:**
- Produces a pushed commit on `origin/main`.

- [ ] **Step 1: Syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/AffiliateService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/RiskService.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAffiliateBalanceTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserRiskOpsTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAdminRiskOpsControllerTest.php
```

- [ ] **Step 2: Full test suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Diff review**

Run:

```powershell
git diff --check
git diff --stat
git diff -- app/User/AffiliateService.php app/User/WithdrawalService.php app/User/RiskService.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php tests/Feature/User/UserRiskOpsTest.php tests/Feature/User/UserAdminRiskOpsControllerTest.php docs/superpowers/plans/2026-07-07-p20-admin-ops-errors-chinese.md
```

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p20-admin-ops-errors-chinese.md app/User/AffiliateService.php app/User/WithdrawalService.php app/User/RiskService.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserAdminAffiliateBalanceControllerTest.php tests/Feature/User/UserRiskOpsTest.php tests/Feature/User/UserAdminRiskOpsControllerTest.php
git commit -m "fix: localize admin ops validation"
git push origin main
```

Expected result: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers commission approve/reject/reverse errors, withdrawal approve/reject/payout/failure/idempotency errors, and risk review errors through both service and admin controller surfaces.
- Placeholder scan: no placeholders, TODOs, or vague implementation steps remain.
- Scope check: excludes module-container lifecycle/review wording by design for the next P.
