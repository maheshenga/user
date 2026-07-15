# Balance Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace wallet float arithmetic with integer cents, add business-operation idempotency, and add read-only reconciliation.

**Architecture:** `Money` owns exact arithmetic, `BalanceOperationKey` owns canonical operation identity, `BalanceLedgerService` remains the sole mutation boundary, and `BalanceReconciliationService` audits snapshots without repairing data.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, SQLite/MySQL migrations, PHPUnit 12.

## Global Constraints

- Keep database amount columns as `DECIMAL(12,2)`.
- Keep existing service and Gateway signatures source compatible.
- Never repair financial data automatically.
- Keep all balance mutations under the existing user row lock and transaction.

---

### Task 1: Exact Money Value Object

**Files:**
- Create: `app/User/Money.php`
- Create: `tests/Unit/User/MoneyTest.php`

**Interfaces:**
- Produces: `Money::from(mixed): Money`, `add()`, `subtract()`, `compareTo()`, `multiplyRate()`, `toString()`.

- [x] **Step 1: Write failing unit tests**

```php
$this->assertSame('0.30', Money::from('0.10')->add(Money::from('0.20'))->toString());
$this->assertSame('1.01', Money::from('1.005')->toString());
$this->assertSame('12.35', Money::from('123.45')->multiplyRate('0.1000')->toString());
```

- [x] **Step 2: Verify tests fail because `Money` does not exist**

- [x] **Step 3: Implement integer-cent parsing and arithmetic**

- [x] **Step 4: Run `MoneyTest` and verify it passes**

### Task 2: Ledger Operation Identity

**Files:**
- Create: `app/User/BalanceOperationKey.php`
- Create: `database/migrations/2026_07_15_000002_add_balance_operation_keys.php`
- Modify: `tests/Feature/User/UserAffiliateBalanceTest.php`

**Interfaces:**
- Produces: `BalanceOperationKey::make(int, string, string, ?string, ?int): ?string` and `user_balance_ledger.operation_key`.

- [x] **Step 1: Write replay and conflict tests**

Call `credit()` twice with the same source and assert one ledger row. Repeat with a different amount and assert `InvalidArgumentException('余额操作幂等键冲突。')`.

- [x] **Step 2: Verify replay tests fail**

- [x] **Step 3: Add the operation-key migration**

Backfill complete historical sources, reject duplicate canonical tuples, and add a unique index named `user_balance_ledger_operation_unique`.

- [x] **Step 4: Implement the operation-key builder**

Return `null` unless both source fields exist; otherwise hash JSON encoded tuple values with `JSON_THROW_ON_ERROR`.

### Task 3: Exact and Idempotent Balance Mutations

**Files:**
- Modify: `app/User/BalanceLedgerService.php`
- Modify: `app/Models/UserBalanceLedger.php`

**Interfaces:**
- Consumes: `Money` and `BalanceOperationKey`.
- Produces: exact snapshots and replay-safe mutations.

- [x] **Step 1: Replace float helpers with `Money` operations**

Parse amount before entering the transaction, calculate all snapshots as `Money`, and persist `toString()` values.

- [x] **Step 2: Add replay lookup under the user lock**

Return an exact prior operation; reject a prior operation whose amount or identity does not match.

- [x] **Step 3: Run affiliate, withdrawal, activation, and admin balance tests**

### Task 4: Read-Only Reconciliation

**Files:**
- Create: `app/User/BalanceReconciliationService.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/User/UserBalanceReconciliationTest.php`

**Interfaces:**
- Produces: `inspect(?int $userId = null, int $limit = 1000): array` and `user:balance:reconcile`.

- [x] **Step 1: Write failing clean and tampered-balance tests**

Assert a service-created ledger exits `0`; directly alter the account balance and assert exit `1` with `account_snapshot_mismatch`.

- [x] **Step 2: Implement continuity and account snapshot checks**

Return `checked_users`, `issue_count`, and issue arrays containing `user_id`, `code`, `ledger_id`, `expected`, and `actual`.

- [x] **Step 3: Register the read-only command**

Support `--user` and `--limit`; emit each issue as JSON and return `Command::FAILURE` when issues exist.

### Task 5: Review and Commit

**Files:**
- Review every file changed by Tasks 1-4.

- [x] **Step 1: Run Pint and focused tests**
- [x] **Step 2: Run the complete PHPUnit suite or equivalent clean directory groups**
- [x] **Step 3: Run migration up/down round trip and inspect staged diff**
- [x] **Step 4: Commit with `fix: enforce balance integrity`**
