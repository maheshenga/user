# Notification Outbox Delivery Design

## Goal

Make notification dispatch concurrency-safe, recover stale work, route notification templates by type, and stop retrying permanently broken rows forever.

## Delivery State Machine

```text
pending -> processing -> sent
                      -> pending (retryable failure)
                      -> failed  (attempt limit reached)
processing -> pending (expired lease recovery)
```

Workers claim due rows inside a transaction by assigning a unique `lock_token`, `locked_at`, and `processing` status. Network delivery happens after the transaction. A recent `processing` row cannot be claimed by another worker. Expired leases are returned to `pending` before each dispatch cycle.

This provides at-least-once delivery. A process can still die after a provider accepts a message but before the row is marked sent; provider-level idempotency is required for stronger guarantees.

## Retry Policy

Configuration defines maximum attempts, lease duration, and retry delay. A failure increments `attempt_count`, stores a bounded error, and either schedules another attempt or records terminal `failed_at`. Missing SMS configuration follows the same policy and never reports success.

## Template Routing

- `password_reset` renders the password reset token/code message.
- `module:<name>` renders the module-provided `message` or `body` as a generic notification.
- Unknown types fail explicitly.

All email delivery uses a generic `UserNotificationMail`. Password-reset token and code are removed from encrypted payload after successful delivery.

## Compatibility

Existing queue producers and command names remain valid. `sendPending()` retains `sent` and `failed` counters and adds `dead` and `recovered`. A first transient failure remains `pending`, preserving current behavior.

## Testing

- Schema and casts for lease and terminal failure fields.
- Recent claims are not dispatched twice.
- Expired claims recover and send.
- Repeated failure reaches terminal `failed`.
- Password-reset email still sends and removes secrets.
- Module notification uses its own message instead of password-reset copy.
