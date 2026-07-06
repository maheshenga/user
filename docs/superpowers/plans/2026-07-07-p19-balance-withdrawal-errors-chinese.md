# P19 Balance and Withdrawal Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This session executes inline without subagents per user instruction.

**Goal:** Localize balance-ledger and user withdrawal request basic errors to Chinese so wallet, ledger, and withdrawal flows do not expose English validation messages.

**Architecture:** Keep balance mutation, withdrawal freezing, account snapshots, and public API shapes unchanged. Replace only base wallet/withdrawal exception messages and tests that assert those messages. Leave admin payout/review and affiliate commission review wording to a later P to keep this slice small and reviewable.

**Tech Stack:** PHP 8.3, Laravel feature tests, SQLite test runner, existing `BalanceLedgerService`, `WithdrawalService`, `UserAffiliateBalanceTest`, and `UserRiskOpsTest`.

## Global Constraints

- Do not change balance arithmetic, transaction boundaries, ledger directions, or stored status values.
- Do not change withdrawal request creation, freezing, numbering, or response shape.
- Do not change admin payout/review behavior in this P.
- Execute directly in this session; do not dispatch subagents.

---

### Task 1: RED tests for balance and withdrawal Chinese errors

**Files:**
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`
- Modify: `tests/Feature/User/UserRiskOpsTest.php`

**Interfaces:**
- Consumes: `App\User\BalanceLedgerService`
- Consumes: `App\User\WithdrawalService::request(int $userId, string|float $amount, array $accountSnapshot, string $ip): array`
- Produces: failing expectations for Chinese basic wallet and withdrawal errors.

- [ ] **Step 1: Update balance insufficient expectation**

In `UserAffiliateBalanceTest::test_balance_ledger_service_rejects_insufficient_available_balance`, replace:

```php
$this->expectExceptionMessage('Available balance is insufficient.');
```

with:

```php
$this->expectExceptionMessage('可用余额不足。');
```

- [ ] **Step 2: Update admin adjustment basic validation expectations**

In `UserAffiliateBalanceTest::test_balance_ledger_service_admin_adjust_requires_reason_and_admin_id`, replace:

```php
$this->assertSame('Adjustment reason is required.', $exception->getMessage());
$this->assertSame('Admin id is required.', $exception->getMessage());
```

with:

```php
$this->assertSame('调整原因不能为空。', $exception->getMessage());
$this->assertSame('管理员 ID 不能为空。', $exception->getMessage());
```

- [ ] **Step 3: Update withdrawal invalid amount and insufficient balance expectations**

In `UserRiskOpsTest::test_withdrawal_service_rejects_invalid_or_insufficient_amounts`, replace:

```php
$this->assertSame('Amount must be greater than zero.', $exception->getMessage());
$this->expectExceptionMessage('Available balance is insufficient.');
```

with:

```php
$this->assertSame('金额必须大于 0。', $exception->getMessage());
$this->expectExceptionMessage('可用余额不足。');
```

- [ ] **Step 4: Add withdrawal empty account snapshot assertion**

Still in `test_withdrawal_service_rejects_invalid_or_insufficient_amounts`, after the invalid amount loop add:

```php
try {
    $service->request($user->id, '1.00', [], '127.0.0.6');
    $this->fail('Expected empty withdrawal account snapshot to fail.');
} catch (InvalidArgumentException $exception) {
    $this->assertSame('提现账户信息不能为空。', $exception->getMessage());
}
```

- [ ] **Step 5: Run RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAffiliateBalanceTest|UserRiskOpsTest"
```

Expected result: FAIL because services still return English basic wallet/withdrawal errors.

### Task 2: GREEN implementation

**Files:**
- Modify: `app/User/BalanceLedgerService.php`
- Modify: `app/User/WithdrawalService.php`

**Interfaces:**
- Keeps all public method signatures unchanged.
- Produces Chinese `InvalidArgumentException` messages for basic wallet and withdrawal request errors.

- [ ] **Step 1: Replace `BalanceLedgerService` messages**

Use these exact replacements:

```text
Adjustment reason is required. => 调整原因不能为空。
Admin id is required. => 管理员 ID 不能为空。
Amount must not be zero. => 调整金额不能为 0。
User account not found. => 用户账户不存在。
Unsupported balance direction. => 不支持的余额变动方向。
Available balance is insufficient. => 可用余额不足。
Frozen balance is insufficient. => 冻结余额不足。
Amount must be greater than zero. => 金额必须大于 0。
```

- [ ] **Step 2: Replace basic `WithdrawalService` request messages**

Use these exact replacements:

```text
Withdrawal account snapshot is required. => 提现账户信息不能为空。
Amount must be greater than zero. => 金额必须大于 0。
Withdrawal request not found. => 提现申请不存在。
```

- [ ] **Step 3: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserAffiliateBalanceTest|UserRiskOpsTest"
```

Expected result: PASS.

### Task 3: Verification, review, commit, push

**Files:**
- Review all P19 changes.

**Interfaces:**
- Produces a pushed commit on `origin/main`.

- [ ] **Step 1: Syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/BalanceLedgerService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAffiliateBalanceTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserRiskOpsTest.php
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
git diff -- app/User/BalanceLedgerService.php app/User/WithdrawalService.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserRiskOpsTest.php docs/superpowers/plans/2026-07-07-p19-balance-withdrawal-errors-chinese.md
```

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p19-balance-withdrawal-errors-chinese.md app/User/BalanceLedgerService.php app/User/WithdrawalService.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "fix: localize wallet withdrawal validation"
git push origin main
```

Expected result: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers wallet validation, balance insufficiency, frozen balance insufficiency, missing user account, withdrawal positive amount, missing withdrawal account snapshot, and withdrawal request lookup.
- Placeholder scan: no placeholders, TODOs, or vague implementation steps remain.
- Scope check: excludes admin payout/review and affiliate review wording by design for the next P.
