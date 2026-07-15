# Module API Request Lease Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recover stale module requests with ownership-safe execution leases.

**Architecture:** Claim and reclaim happen under the existing user row lock. Callback execution receives a unique lease token, and finalization uses a conditional ownership check.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, SQLite/MySQL migrations, PHPUnit 12.

## Global Constraints

- Preserve current idempotent replay and quota behavior.
- Keep callbacks outside the database claim transaction.
- Never allow an expired worker to overwrite a newer owner.
- Document at-least-once callback semantics.

---

### Task 1: Lease Schema and Failing Tests

**Files:**
- Create: `database/migrations/2026_07_15_000004_add_module_api_request_leases.php`
- Modify: `app/Models/ModuleApiRequest.php`
- Create: `tests/Feature/Modules/ModuleApiRequestLeaseTest.php`

- [x] **Step 1: Write schema, active lease, stale reclaim, and ownership-loss tests**
- [x] **Step 2: Run tests and verify the missing lease behavior fails**
- [x] **Step 3: Add fields, indexes, casts, and legacy processing backfill**

### Task 2: Lease-Aware Claim and Finalization

**Files:**
- Modify: `app/Modules/ModuleApiRequestService.php`
- Modify: `config/modules.php`

- [x] **Step 1: Assign lease token and expiry when creating a request**
- [x] **Step 2: Reclaim matching expired processing records and increment attempts**
- [x] **Step 3: Keep active processing requests blocked**
- [x] **Step 4: Conditionally complete or fail only while the lease token is owned**
- [x] **Step 5: Return typed `request_lease_lost` on ownership loss**

### Task 3: Verification and Commit

- [x] **Step 1: Run Pint and lease tests**
- [x] **Step 2: Run Qingyu API replay, quota, activation, parse, and rewrite regression tests**
- [x] **Step 3: Run migration up/down and inspect staged diff**
- [x] **Step 4: Commit with `fix: recover stale module API requests`**
