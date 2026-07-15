# Notification Outbox Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add lease-based outbox claiming, bounded retries, stale recovery, and type-specific email rendering.

**Architecture:** The dispatcher claims rows transactionally, sends outside the transaction, and finalizes only rows carrying its claim token. A generic Mailable receives already-rendered subject and message text.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, Mail fake, SQLite/MySQL migrations, PHPUnit 12.

## Global Constraints

- Preserve existing notification producers and commands.
- Never log or expose reset token/code values.
- Do not mark SMS as sent without a configured provider.
- Keep retry counts bounded and stale work recoverable.

---

### Task 1: State Schema and Failing Tests

**Files:**
- Create: `database/migrations/2026_07_15_000003_harden_notification_outbox_delivery.php`
- Modify: `app/Models/UserNotificationOutbox.php`
- Modify: `tests/Feature/User/UserPasswordResetNotificationTest.php`

- [ ] **Step 1: Add failing schema, lease, stale recovery, terminal failure, and module-message tests**
- [ ] **Step 2: Run the notification test and verify expected failures**
- [ ] **Step 3: Add `locked_at`, `lock_token`, and `failed_at` with casts and indexes**

### Task 2: Generic Notification Mail

**Files:**
- Create: `app/Mail/UserNotificationMail.php`
- Modify: `app/User/NotificationOutboxDispatcher.php`

- [ ] **Step 1: Route `password_reset` and `module:*` payloads to explicit messages**
- [ ] **Step 2: Reject unknown types and missing module messages**
- [ ] **Step 3: Keep password-reset secret cleanup after success**

### Task 3: Lease Claiming and Retry State Machine

**Files:**
- Create: `config/user_notifications.php`
- Modify: `app/User/NotificationOutboxDispatcher.php`

- [ ] **Step 1: Recover expired processing rows**
- [ ] **Step 2: Claim due rows in one transaction with a UUID token**
- [ ] **Step 3: Finalize only rows owned by the worker claim**
- [ ] **Step 4: Move exhausted rows to `failed` and preserve retryable pending behavior**

### Task 4: Verification and Commit

- [ ] **Step 1: Run Pint and notification tests**
- [ ] **Step 2: Run user-domain and module notification Gateway regression tests**
- [ ] **Step 3: Run migration up/down and inspect staged diff**
- [ ] **Step 4: Commit with `fix: harden notification outbox delivery`**
