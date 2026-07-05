# User Security Hardening Phase 9 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden user authentication and withdrawal payout safety before expanding the product surface.

**Architecture:** Keep current Laravel service boundaries. Add a tiny password hashing boundary used by auth and reset services, and add payout transaction idempotency inside `WithdrawalService::markPaid()` before frozen funds are settled.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, Laravel Hash facade, PHPUnit 12, SQLite test runner.

---

## File Structure

- Create `app/User/UserPasswordHasher.php`
  - Owns password hashing and verification wrappers around Laravel `Hash`.
- Modify `app/User/UserAuthService.php`
  - Inject `UserPasswordHasher`; use it for registration hashing and login verification.
- Modify `app/User/PasswordResetService.php`
  - Inject `UserPasswordHasher`; use it for reset password hashing.
- Modify `app/User/WithdrawalService.php`
  - Reserve a database-unique payout reference before settling frozen funds.
- Create `database/migrations/2026_07_06_000001_create_user_withdrawal_payout_reference_table.php`
  - Stores one unique payout reference per external payout transaction.
- Create `tests/Unit/User/UserPasswordHasherTest.php`
  - Covers password hash shape and verification.
- Modify `tests/Feature/User/UserAuthTest.php`
  - Covers registration/login through the explicit hasher boundary.
- Modify `tests/Feature/User/UserPasswordResetTest.php`
  - Covers reset password old/new login behavior.
- Modify `tests/Feature/User/UserRiskOpsTest.php`
  - Covers duplicate payout transaction id rejection without duplicate ledger effects.

---

## Task 1: Explicit Password Hashing Boundary

**Files:**

- Create: `app/User/UserPasswordHasher.php`
- Create: `tests/Unit/User/UserPasswordHasherTest.php`
- Modify: `app/User/UserAuthService.php`
- Modify: `app/User/PasswordResetService.php`
- Modify: `tests/Feature/User/UserAuthTest.php`
- Modify: `tests/Feature/User/UserPasswordResetTest.php`

- [ ] **Step 1: Add failing unit test for password hasher**

Create `tests/Unit/User/UserPasswordHasherTest.php`:

```php
<?php

namespace Tests\Unit\User;

use App\User\UserPasswordHasher;
use Tests\TestCase;

class UserPasswordHasherTest extends TestCase
{
    public function test_hash_creates_non_plaintext_password_that_can_be_verified(): void
    {
        $hasher = new UserPasswordHasher();

        $hash = $hasher->hash('secret123');

        $this->assertNotSame('secret123', $hash);
        $this->assertTrue($hasher->verify('secret123', $hash));
        $this->assertFalse($hasher->verify('wrong-password', $hash));
    }
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Unit\User\UserPasswordHasherTest.php
```

Expected: FAIL because `App\User\UserPasswordHasher` does not exist.

- [ ] **Step 3: Implement password hasher**

Create `app/User/UserPasswordHasher.php`:

```php
<?php

namespace App\User;

use Illuminate\Support\Facades\Hash;

final class UserPasswordHasher
{
    public function hash(string $password): string
    {
        return Hash::make($password);
    }

    public function verify(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }
}
```

- [ ] **Step 4: Verify GREEN for hasher**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Unit\User\UserPasswordHasherTest.php
```

Expected: PASS with 1 test.

- [ ] **Step 5: Add feature tests for explicit auth/reset behavior**

In `tests/Feature/User/UserAuthTest.php`, add this test after `test_user_can_register_with_mobile_only`:

```php
public function test_registered_password_hash_supports_login_and_rejects_wrong_password(): void
{
    $service = app(UserAuthService::class);

    $service->register([
        'email' => 'p9-hash@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $rawPassword = DB::table('user_account')->where('email', 'p9-hash@example.com')->value('password');

    $this->assertIsString($rawPassword);
    $this->assertNotSame('secret123', $rawPassword);
    $this->assertTrue(Hash::check('secret123', $rawPassword));

    $login = $service->login([
        'account' => 'p9-hash@example.com',
        'password' => 'secret123',
    ], '127.0.0.2');

    $this->assertSame('p9-hash@example.com', $login['user']['email']);

    try {
        $service->login([
            'account' => 'p9-hash@example.com',
            'password' => 'wrong-password',
        ], '127.0.0.2');

        $this->fail('Expected wrong password to fail.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('Invalid account or password.', $exception->getMessage());
    }
}
```

In `tests/Feature/User/UserPasswordResetTest.php`, add this test after `test_valid_token_resets_password_marks_used_and_writes_security_log`:

```php
public function test_reset_password_hash_supports_new_login_and_rejects_old_password(): void
{
    app(UserAuthService::class)->register([
        'email' => 'p9-reset@example.com',
        'password' => 'old-password',
    ], '127.0.0.1');

    app(PasswordResetService::class)->requestReset([
        'account' => 'p9-reset@example.com',
    ], '127.0.0.2');
    $outbox = UserNotificationOutbox::query()->firstOrFail();

    app(PasswordResetService::class)->resetPassword([
        'account' => 'p9-reset@example.com',
        'token' => $outbox->payload_json['token'],
        'password' => 'new-password',
    ], '127.0.0.3');

    $rawPassword = DB::table('user_account')->where('email', 'p9-reset@example.com')->value('password');

    $this->assertIsString($rawPassword);
    $this->assertNotSame('new-password', $rawPassword);
    $this->assertTrue(Hash::check('new-password', $rawPassword));
    $this->assertFalse(Hash::check('old-password', $rawPassword));

    $login = app(UserAuthService::class)->login([
        'account' => 'p9-reset@example.com',
        'password' => 'new-password',
    ], '127.0.0.4');

    $this->assertSame('p9-reset@example.com', $login['user']['email']);

    try {
        app(UserAuthService::class)->login([
            'account' => 'p9-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.4');

        $this->fail('Expected old password to fail after reset.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('Invalid account or password.', $exception->getMessage());
    }
}
```

- [ ] **Step 6: Run feature tests before service refactor**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php --filter "registered_password_hash|user_can_login"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php --filter "reset_password_hash|valid_token"
```

Expected: PASS. These tests document current behavior before the refactor.

- [ ] **Step 7: Refactor services to use UserPasswordHasher**

Modify `app/User/UserAuthService.php`:

```php
public function __construct(
    private readonly InviteService $invites,
    private readonly RiskService $risk,
    private readonly UserPasswordHasher $passwords
) {
}
```

Change registration create payload:

```php
'password' => $this->passwords->hash($password),
```

Change login check:

```php
if ($user === null || ! $this->passwords->verify($password, $user->password)) {
```

Remove the unused `use Illuminate\Support\Facades\Hash;` import from `UserAuthService`.

Modify `app/User/PasswordResetService.php`:

```php
public function __construct(
    private readonly UserSecurityLogService $securityLogs,
    private readonly PasswordResetNotificationService $notifications,
    private readonly UserPasswordHasher $passwords
) {
}
```

Change reset password write:

```php
'password' => $this->passwords->hash($password),
```

- [ ] **Step 8: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Unit\User\UserPasswordHasherTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php --filter "registered_password_hash|user_can_login|register"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php --filter "reset_password_hash|valid_token|password_reset_endpoints"
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserPasswordHasher.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserAuthService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\PasswordResetService.php
```

Expected: all pass.

Commit:

```powershell
git add app/User/UserPasswordHasher.php app/User/UserAuthService.php app/User/PasswordResetService.php tests/Unit/User/UserPasswordHasherTest.php tests/Feature/User/UserAuthTest.php tests/Feature/User/UserPasswordResetTest.php
git commit -m "feat: harden user password hashing"
```

---

## Task 2: Withdrawal Payout Transaction Idempotency

**Files:**

- Modify: `app/User/WithdrawalService.php`
- Create: `database/migrations/2026_07_06_000001_create_user_withdrawal_payout_reference_table.php`
- Modify: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Add failing duplicate payout transaction test**

Add this test after `test_withdrawal_service_mark_paid_settles_frozen_balance_and_records_proof` in `tests/Feature/User/UserRiskOpsTest.php`:

```php
public function test_withdrawal_service_rejects_duplicate_paid_payout_transaction_without_second_settlement(): void
{
    $firstUser = $this->createAccount('withdraw-idempotent-1@example.com', '50.00');
    $secondUser = $this->createAccount('withdraw-idempotent-2@example.com', '50.00');
    $service = app(WithdrawalService::class);

    $first = $service->request($firstUser->id, '12.00', ['account_no' => 'first'], '127.0.0.6');
    $second = $service->request($secondUser->id, '8.00', ['account_no' => 'second'], '127.0.0.6');
    $service->approve($first['id'], 7);
    $service->approve($second['id'], 7);

    $service->markPaid($first['id'], [
        'method' => 'manual_bank',
        'transaction_id' => 'BANK-DUPLICATE-001',
        'proof' => ['receipt_no' => 'R001'],
    ], 8);

    try {
        $service->markPaid($second['id'], [
            'method' => 'manual_bank',
            'transaction_id' => 'BANK-DUPLICATE-001',
            'proof' => ['receipt_no' => 'R002'],
        ], 8);

        $this->fail('Expected duplicate payout transaction id to fail.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('Payout transaction id has already been used.', $exception->getMessage());
    }

    $this->assertSame(1, DB::table('user_balance_ledger')->where('type', 'withdraw_success')->count());

    $this->assertDatabaseHas('user_withdrawal_request', [
        'id' => $second['id'],
        'status' => 'approved',
        'ledger_success_id' => null,
        'payout_transaction_id' => '',
    ]);
    $this->assertDatabaseHas('user_account', [
        'id' => $secondUser->id,
        'available_balance' => '42.00',
        'frozen_balance' => '8.00',
    ]);
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter duplicate_paid_payout
```

Expected: FAIL because the second withdrawal can currently be marked paid with a duplicate transaction id.

- [ ] **Step 3: Implement database-backed duplicate payout guard**

Create `database/migrations/2026_07_06_000001_create_user_withdrawal_payout_reference_table.php` with a unique `reference_key` and unique `withdrawal_id`. This prevents concurrent transactions from settling two withdrawals with the same external payout reference.

In `app/User/WithdrawalService.php`, inside the `markPaid()` transaction, after loading `$withdrawal` and before calling `settleFrozen()`, add:

```php
$this->reservePayoutReference((int) $withdrawal->id, $method, $transactionId, $adminId);
```

Add a private `reservePayoutReference()` helper that inserts into `user_withdrawal_payout_reference` and converts unique-constraint failures to `InvalidArgumentException('Payout transaction id has already been used.')`.

- [ ] **Step 4: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "duplicate_paid_payout|mark_paid|records_payout_failure"
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\WithdrawalService.php
```

Expected: all pass.

Commit:

```powershell
git add app/User/WithdrawalService.php database/migrations/2026_07_06_000001_create_user_withdrawal_payout_reference_table.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: prevent duplicate withdrawal payout settlement"
```

---

## Task 3: Review and Verification

**Files:**

- Review all files changed in Tasks 1-2.

- [ ] **Step 1: Run focused user tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Unit\User\UserPasswordHasherTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php
```

Expected: all pass.

- [ ] **Step 2: Run full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserPasswordHasher.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserAuthService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\PasswordResetService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\WithdrawalService.php
git diff --check
```

Expected: clean.

- [ ] **Step 4: Review checklist**

Confirm:

- Registration writes a hash, not plaintext.
- Login verifies through `UserPasswordHasher`.
- Password reset writes a hash, not plaintext.
- New password works after reset.
- Old password fails after reset.
- Public payloads still exclude password.
- Duplicate paid payout transaction references are rejected before settlement.
- Duplicate rejected/paid terminal transitions do not create extra ledger rows.
- No API route names changed.
- No P1-P8 business behavior changed outside the security hardening scope.

- [ ] **Step 5: Commit review checkpoint**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review user security hardening phase"
```

---

## Plan Self-Review

- Spec coverage: Password hashing, reset hashing, payout idempotency, focused tests, full suite, and review are all covered.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: all class names, methods, and paths match current project conventions.
- Scope guard: no frontend pages, provider integrations, or unrelated business rule changes are included.
