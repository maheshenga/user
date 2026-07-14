# Module Platform Hardening Design

## Status

Approved for implementation on 2026-07-14 after the Qingyu `1.4.0` desktop and module audit.

## Goal

Turn the existing internal module runtime into a durable, administrator-reviewed module platform that can safely operate team modules and reviewed third-party modules over long release cycles.

## Trust Boundary

PHP modules execute inside the Laravel process and therefore remain fully trusted after approval. Manifest permissions document and validate requested capabilities, but they are not a filesystem, database, environment-variable, or network sandbox. Third-party in-process modules must be reviewed by an administrator and bound to an immutable artifact hash. Stronger isolation requires a separate process or container and is outside this implementation.

## Release Model

Each reviewed version is copied to an immutable directory under `storage/modules/releases/{module}/{version}-{hash}`. `system_module_release` stores the version, artifact path, SHA-256 tree hash, manifest snapshot, source type, review decision, reviewer, signature, and activation timestamps.

Uploading a ZIP or approving a local source module creates a pending release without changing the active module. Approval signs the exact artifact hash. Installation or upgrade runs migrations while the module is in an `upgrading` state, then atomically switches `system_module.path` and `active_release_id` in the database. Rollback switches to a previous immutable release after explicitly reversing migrations that do not exist in the target release.

Production local-directory upgrades are disabled. Team modules use the same staged release path as uploaded packages so a modified live source directory can never be mistaken for a backup.

## Review And Integrity

- Every new version requires administrator review.
- ZIP uploads default to `community` trust; local repository modules default to `private` trust.
- `schema_version`, semantic version, namespace, module type, PHP version, host version, capabilities, dependencies, conflicts, paths, menus, and external domain declarations are validated.
- `partner` and `community` releases require a local HMAC signature in production.
- The signing key comes from `MODULE_SIGNING_KEY`; it is never stored in the repository or returned by an API.
- Enabled releases are integrity-checked before their providers, controllers, views, routes, or assets are loaded.

## Module-Owned Data

`ModuleContext` identifies the calling module. Qingyu member operations default to `source_module = qingyu_ip_agent`. Activation-code batches and redemption records gain `owner_module`; Qingyu can only list, generate, redeem, and report its own codes. Global user administration remains available through the existing host user operations area.

## API Policy

Module access-token abilities come from the approved active manifest and must belong to the host allowlist. Token issue, refresh, and protected requests require the module to be enabled. Disabling, uninstalling, or rejecting an active module revokes all module access and refresh sessions.

The stable bearer-token surface includes authentication plus user-owned profile, VIP, invitation, balance, and ledger reads. Administrative balance, commission, risk, and notification operations remain server-side services and are exposed to modules through typed host gateway contracts rather than public bearer endpoints.

## Request Reliability

Protected module mutations carry an idempotency key. The host records one result per module, user, operation, and key, rejects concurrent duplicates, and returns cached successful results. Daily and concurrent limits are configured per module operation. API errors use stable machine codes and include a request ID.

Desktop timeouts are operation-specific: authentication 15 seconds, video parsing 35 seconds, and cloud rewrite 60 seconds. Backend provider budgets remain below the corresponding desktop timeout.

## Menu And Audit Ownership

`system_module_menu` maps managed EasyAdmin menu rows to their module. Install and upgrade reconcile desired menus; disable and uninstall hide owned menus; enable restores them. Module lifecycle logs record old/new versions, duration, artifact hash, and reviewer actions.

Qingyu operation logs record request ID, user ID, API session ID, and module version. Passwords, tokens, activation codes, account identifiers, emails, mobiles, and user content remain masked or summarized.

## Failure Handling

- Invalid manifests remain visible as diagnostics instead of disappearing silently.
- Migration execution explicitly compensates migrations already applied in the current run when a later migration fails.
- A failed activation restores the previous module status and leaves the active release pointer unchanged.
- MySQL DDL rollback is treated as compensation, not transaction magic. Destructive migrations remain a manual release gate.
- Failed release artifacts and review records are retained for audit, while secrets and plaintext credentials are never retained.

## Verification

Required gates are focused red-green tests, the full Laravel PHPUnit suite with SQLite enabled, desktop Node tests, PHP and Node syntax checks, route inspection, production migration, module health checks, and public smoke tests for authentication and Qingyu bootstrap/profile/logout.

