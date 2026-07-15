# Qingyu Audit Secret Masking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Qingyu audit payload masking independent of client key naming convention.

**Architecture:** Canonicalize payload keys once, classify sensitive key families, then mask before recursively handling safe containers. Keep the existing audit persistence and public method contract.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit 12.

## Global Constraints

- Never persist a complete credential, token, or activation code in `masked_payload_json`.
- Preserve existing mobile, email, and EA8 last-four masking.
- Prefer masking a non-secret `*code` field over leaking a newly named secret code.
- Do not rewrite historical audit rows.
- Do not change the operation-log schema.

---

### Task 1: Add Failing Alias and Nested-Secret Tests

**Files:**
- Modify: `tests/Feature/Modules/QingyuIpAgentModuleTest.php`

- [x] **Step 1: Extend the existing audit masking payload**

Add `activationCode`, `refresh-token`, `api_key`, `clientSecret`, and an array-valued `credentials` field containing unique raw secret values.

- [x] **Step 2: Add nested non-sensitive container coverage**

Add `metadata => ['sessionToken' => '...']` and keep `safe_note => 'visible'`.

- [x] **Step 3: Assert every raw secret is absent**

Keep assertions for masked email/mobile, EA8 last-four, and visible safe data.

- [x] **Step 4: Run the focused test and verify RED**

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Modules/QingyuIpAgentModuleTest.php --filter audit_log_masks_sensitive_payloads
```

Expected: failure because at least `activationCode`, separator aliases, or array-valued sensitive keys remain visible.

### Task 2: Canonicalize and Mask Sensitive Key Families

**Files:**
- Modify: `modules/QingyuIpAgent/src/Services/AuditLogService.php`

- [x] **Step 1: Replace the exact alias list with canonical families**

Add `canonicalKey(string|int $key): string` that lowercases and removes non-alphanumeric characters.

- [x] **Step 2: Add `isSensitiveKey()`**

Return true for password/passwd/token/secret/credential families, authorization/cookie/session/api-key/private-key keys, and keys equal to or ending in `code`.

- [x] **Step 3: Mask before recursion**

If a key is sensitive, mask its scalar value with the existing EA8 logic or return `******` for structured values. Only recurse when the container key itself is safe.

- [x] **Step 4: Use canonical keys for mobile/email checks**

Preserve their current output while allowing separator variants.

- [x] **Step 5: Run the focused test and verify GREEN**

Run the Task 1 command and expect the masking test to pass.

### Task 3: Review, Verify, and Commit

- [x] **Step 1: Run Pint on the service and test**

- [x] **Step 2: Run the complete `QingyuIpAgentModuleTest`**

- [x] **Step 3: Run all module tests**

```bash
APP_TIMEZONE=Asia/Shanghai DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Modules tests/Unit/Modules
```

- [x] **Step 4: Review masking coverage and staged diff**

Confirm classification precedes recursion, safe fields remain visible, no schema change exists, and staged content contains no real credentials.

- [x] **Step 5: Commit implementation**

```bash
git commit -m "fix: harden Qingyu audit secret masking"
```
