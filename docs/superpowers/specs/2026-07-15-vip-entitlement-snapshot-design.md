# VIP Entitlement Snapshot Design

## Goal

Make activation-code VIP grants deterministic, idempotent, and independent from later edits to a VIP plan.

## Current Problems

- `activation_code_batch.duration_days` is stored but redemption ignores it.
- `VipService` keeps the maximum historical `vip_level`, so an expired high-tier user can reactivate a lower plan and regain the old tier.
- `user_vip_record` does not prevent the same business source from being granted twice.
- Existing activation-code batches depend on the current mutable `vip_plan` row for level and duration.

## Design

1. Add `vip_level` to `activation_code_batch` as the level snapshot captured when the batch is created.
2. Add `vip_level` to `user_vip_record` so historical grants do not depend on a mutable plan row.
3. Extend `VipService::grant()` with optional snapshot arguments: `durationDays` and `vipLevel`. Existing callers remain source compatible.
4. When the existing VIP expiry is still in the future, append the new duration and keep the higher of the currently active level and the snapshot level.
5. When the existing VIP expiry has passed, start from now and use only the snapshot level.
6. Make `(source_type, source_id)` unique in `user_vip_record`. A repeated grant returns the original record and current account summary instead of extending twice.
7. Activation-code redemption passes the batch snapshots into `VipService::grant()`.

## Data Migration

The migration adds `activation_code_batch.vip_level` and `user_vip_record.vip_level` with default `0`, then backfills them from the referenced VIP plan. Runtime code falls back to the plan level for legacy rows that still contain `0`.

The unique VIP source constraint is added after checking for duplicate source rows. The migration fails with an explicit message if duplicate grants already exist, preventing silent data loss.

## Error Handling

- Duration must be at least one day.
- VIP level must be at least one.
- Existing invalid plan or user errors remain unchanged.
- Idempotent replay does not create a second VIP record or extend expiry.

## Testing

- A batch duration override controls the granted expiry.
- A batch retains its snapshotted level after the plan changes.
- Expired high-tier membership followed by a lower-tier grant results in the lower tier.
- Active high-tier membership extended by a lower-tier grant retains the active high tier.
- Repeating the same source grant is idempotent.
- Migration schema and existing activation flows remain covered.

## Compatibility

No public HTTP route or response field is removed. Existing direct calls to `VipService::grant()` continue to use the current plan values unless snapshots are supplied.
