# User Security Hardening Phase 9 Design

## Goal

Harden the completed user operations system for long-term operation by fixing password storage and tightening money-movement state transitions before adding more product surface.

## Context

The current system already has user registration/login, password reset, invite binding, VIP activation codes, two-level affiliate commissions, balance ledger, withdrawal review, risk events, notification outbox, and a backend operations dashboard. The P8 validation suite passes on the current `main` branch.

During the P9 analysis, two service-level risks were identified:

- `UserAuthService::register()` passes the submitted password directly into `UserAccount::create()`.
- `PasswordResetService::resetPassword()` passes the submitted replacement password directly into `UserAccount::forceFill()`.

Current tests pass because `App\Models\UserAccount` uses Laravel's `password => hashed` cast. P9 keeps that model protection and makes the service-level intent explicit so future maintainers do not accidentally remove or bypass password hashing.

The withdrawal state machine already uses database transactions and `lockForUpdate()`, but it needs explicit regression coverage for duplicate terminal transitions. The key risk is accidentally allowing repeated reject or paid transitions to create extra ledger rows or change balances twice.

## Scope

Included:

- Hash new registration passwords with Laravel `Hash::make()`.
- Hash password-reset replacement passwords with Laravel `Hash::make()`.
- Preserve existing login behavior through `Hash::check()`.
- Add regression tests proving stored passwords are not plaintext and login still works.
- Add regression tests proving terminal withdrawal transitions are not repeatable and do not create duplicate ledger effects.
- Keep response payloads free of password hashes and reset secrets.
- Run focused and full verification before merge.

Excluded:

- New public frontend pages.
- New SMS/email providers.
- New payment or payout provider integration.
- Changing existing user API route names.
- Reworking the EasyAdmin layout.
- Changing commission business rates or payout rules.

## Architecture

P9 keeps the existing service boundaries. Password hardening stays inside the service methods that write passwords and continues to benefit from the model-level hashed cast:

- `App\User\UserAuthService::register()`
- `App\User\PasswordResetService::resetPassword()`

Money state-machine hardening stays in `WithdrawalService` and adds a database-unique payout reference table. The service must reserve a unique payout reference before frozen funds are settled so concurrent requests cannot pay two withdrawals with the same external transaction id. The existing `WithdrawalService` remains the authority for:

- request: available balance to frozen balance
- approve: pending to approved
- markPaid: approved or payout_failed to paid, frozen balance settled
- markPayoutFailed: approved or payout_failed to payout_failed
- reject: pending, approved, or payout_failed to rejected, frozen balance returned

## Data Flow

Registration:

```text
POST /user/register
  -> AuthController::register
  -> UserAuthService::register
  -> Hash::make(password)
  -> user_account.password
  -> login later checks with Hash::check
```

Password reset:

```text
POST /user/password/reset
  -> AuthController::resetPassword
  -> PasswordResetService::resetPassword
  -> verify token/code hashes
  -> Hash::make(new password)
  -> user_account.password
  -> logout current user session if applicable
```

Withdrawal terminal transitions:

```text
request -> pending + frozen ledger
approve -> approved
markPaid -> paid + settle frozen ledger
reject -> rejected + unfreeze ledger
```

Terminal states `paid` and `rejected` must not be able to generate another settlement, unfreeze, or payout failure effect.
The external payout tuple `payout_method + payout_transaction_id` is represented by a unique hash in `user_withdrawal_payout_reference`.

## Error Handling

Password validation remains unchanged:

- registration password must be at least 6 characters
- reset password must be 6 to 72 characters

Withdrawal error messages remain existing service-level `InvalidArgumentException` messages. P9 adds tests to lock expected behavior rather than broadening the API surface.

## Testing Strategy

The phase uses TDD:

1. Add failing tests that assert registration and password-reset storage is not plaintext and passes `Hash::check`.
2. Implement the minimal password hashing change.
3. Add failing tests for duplicate terminal withdrawal transitions if current behavior is unsafe.
4. Implement only the minimal state guard changes required by those tests.
5. Run focused user tests and the full SQLite suite.

Required verification:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php --filter password
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php --filter password
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter withdrawal
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

## Acceptance Criteria

- A newly registered user has a non-plaintext password hash in `user_account.password`.
- A newly registered user can still log in with the original plaintext password.
- A reset password is stored as a non-plaintext hash.
- A user can log in with a reset password.
- Old password no longer works after reset.
- Password hash never appears in public user payloads.
- A paid withdrawal cannot be marked paid again, rejected, or marked failed.
- A rejected withdrawal cannot be paid or rejected again.
- Duplicate terminal attempts do not create extra `user_balance_ledger` rows.
- Full SQLite test suite passes.

## Operational Notes

Existing local data created before this fix may contain plaintext test passwords. P9 changes future writes. A later maintenance phase can add a one-time password migration or forced password reset policy if production data exists.

## Spec Self-Review

- Placeholder scan: no placeholders remain.
- Scope check: focused on P0 security hardening only.
- Consistency check: service names, routes, and test commands match the current Laravel project.
- Ambiguity check: old plaintext data migration is explicitly excluded from this phase.
