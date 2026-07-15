# Module API Request Lease Design

## Goal

Allow crashed `processing` module API requests to recover while preventing expired workers from overwriting a newer execution.

## Lease Model

`module_api_request` gains `lease_token`, `lease_expires_at`, and `attempt_count`. New requests start with attempt one and a configurable lease. Existing legacy `processing` rows are migrated with an expired lease so the next identical request can reclaim them.

## Claim Rules

- A completed request replays its stored response.
- A failed request replays its typed failure.
- A processing request with an active lease returns `request_in_progress`.
- A processing request with an expired or missing lease can be reclaimed only when its payload hash matches.
- Reclaiming assigns a new token, extends expiry, and increments attempts.
- Payload mismatch always returns `idempotency_conflict`.

## Completion Rules

Callback execution remains outside the claim transaction. Success and failure updates require the current lease token. A worker that loses ownership returns `request_lease_lost` and cannot overwrite the current owner.

This is still at-least-once callback execution. Operations with external side effects must retain their own business idempotency keys.

## Compatibility

HTTP response shapes and request IDs remain unchanged. Completed and failed replay behavior remains unchanged. The lease defaults to 180 seconds, longer than the current 45-second cloud rewrite timeout.

## Testing

- Lease schema and casts.
- Active processing request remains blocked.
- Expired processing request is reclaimed and completed.
- Reclaim increments attempts and clears lease on completion.
- A worker that changes ownership during callback cannot finalize.
- Existing replay, conflict, quota, and typed-error tests remain green.
