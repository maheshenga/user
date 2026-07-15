# Balance Integrity Design

## Goal

Remove floating-point arithmetic from wallet mutations, prevent duplicate business operations, and provide an operational balance reconciliation command.

## Money Representation

Database columns remain `DECIMAL(12,2)` and public APIs continue returning two-decimal strings. PHP arithmetic uses an immutable `Money` value object backed by integer cents. String inputs are parsed and rounded half-up; float inputs are normalized once at the boundary and never used for arithmetic.

`Money` supports comparison, addition, subtraction, absolute values, and commission-rate multiplication where rates are constrained to `0.0000` through `1.0000`.

## Ledger Idempotency

Add nullable `operation_key` to `user_balance_ledger` with a unique index. For rows with a complete business source, the key is the SHA-256 hash of the canonical tuple:

```text
[user_id, direction, type, source_type, source_id]
```

The migration rejects duplicate historical tuples before backfilling. Source-less administrator adjustments remain non-idempotent because each adjustment is an independent manual operation.

Under the existing user row lock, `BalanceLedgerService` checks the operation key before changing balances. An exact replay returns the original ledger. Reuse with a different amount is an idempotency conflict.

## Reconciliation

`BalanceReconciliationService` verifies:

- each ledger row starts from the previous row's available and frozen snapshots;
- the latest ledger snapshots equal `user_account.available_balance` and `frozen_balance`;
- a non-zero account balance has at least one ledger row.

The `user:balance:reconcile` command is read-only. It exits successfully when no issues are found and returns failure with machine-readable issue lines when inconsistencies exist.

## Compatibility

Existing Balance Gateway and service method signatures remain unchanged. Public ledger responses gain no required field. Existing source-less adjustments, withdrawals, commissions, and activation flows retain their current behavior.

## Testing

- Money parsing, rounding, addition, subtraction, and rate multiplication.
- Exact replay returns one ledger and one balance mutation.
- Conflicting replay is rejected.
- Migration adds and backfills operation keys and rejects duplicate source tuples.
- Reconciliation passes for service-created ledgers and reports tampered balances and broken continuity.
