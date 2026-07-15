# Module Platform Closure Design

## Context

The repository already implements module discovery, immutable releases, administrator review, signing, activation, rollback, dynamic loading, versioned client APIs, host gateways, and the Qingyu reference module. The remaining work is not a replacement of that foundation. It is a closure pass that makes the existing design safe to operate for a long-lived internal platform and establishes an explicit boundary for future third-party execution.

The approved implementation order is P0 baseline safety, P1 lifecycle and authorization, P1 user/API ownership, and P2 contracts and operations. Each phase must remain independently testable and reviewable.

## Considered Approaches

### One large module-container rewrite

This could make every boundary uniform, but it would invalidate the working release, menu, API, and Qingyu integrations. The migration risk is larger than the value and is rejected.

### Patch only the three immediate P0 defects

This would restore local deployment and remove the obvious query injection surface, but it would leave capabilities declarative, lifecycle operations racy, and users bound to a single source module. It is useful as the first delivery, not as the final architecture.

### Incremental closure on the existing architecture

This is the selected approach. Existing public interfaces remain compatible where possible. New shared services centralize runtime eligibility, lifecycle locking, execution identity, membership, contract compatibility, and operations. Legacy desktop routes receive an explicit deprecation window rather than disappearing without a migration path.

## Trust Model

Internal modules with trust level `official` may run in the Laravel process after administrator approval, immutable release signing, and integrity verification. In-process execution is trusted code: capability checks protect accidental misuse and audit host operations, but they are not a sandbox.

Third-party trust levels `partner` and `community` must never execute PHP in the Laravel process. They may be reviewed, stored, and activated only when a compatible external Worker is configured and healthy. The host communicates with that Worker through signed, versioned requests carrying module identity, operation, request ID, deadline, and declared capabilities. A missing or incompatible Worker is a fail-closed condition.

## P0 Baseline Safety

All tracked environment templates and PHPUnit use `Asia/Shanghai`; `PRC` is removed because it is not portable to the production Linux PHP runtime.

Generic administration filters accept only valid column identifiers and known operators. Module controllers provide explicit searchable and sortable field allowlists. `in` and `find_in_set` values are normalized to integers before any SQL expression is constructed, so request data cannot become executable SQL.

Deployment acceptance reports pending migrations, verifies the module release schema, clears stale route state before route verification, checks the signing key configuration, and reports failures without exposing secrets. Database migration remains an explicit deployment step and is never silently performed by a web request.

## P1 Lifecycle and Runtime Eligibility

`ModuleRuntimeEligibility` becomes the single source of truth for whether a module can serve requests, issue tokens, load routes, or execute. It validates status, trust execution policy, active release ownership, release status, signature, artifact path, and integrity through the existing manager services.

All staging, review, activation, rollback, enable, disable, and uninstall operations acquire the same per-module operation lock. The database module row is locked inside the state transition transaction. An operation record stores a unique operation ID, action, previous state, target state, started time, heartbeat, completion state, actor, and error. Health maintenance marks stale operations failed and restores a recoverable module state; it does not attempt to reverse database migrations automatically.

Release activation performs all reversible database state updates in transactions. Files and migrations remain external side effects and are recorded as operation stages. A failed or interrupted operation is visible and retryable rather than being mistaken for an active release.

## P1 Execution Identity and Capabilities

`ModuleExecutionContext` carries the trusted module name, release ID, trust level, capabilities, and request ID for one module invocation. Context is established by host runtime/API middleware, never by module request payloads.

Every host Gateway calls `ModuleCapabilityPolicy` before reading or mutating host resources. Capability names are versioned and use least privilege, including separate read/write grants. Source-module and audit fields are derived from the execution context. Gateways reject calls without a module context unless the call uses an explicit trusted-host context.

Direct host model/service access cannot be technically sandboxed for official in-process PHP. The handbook and automated architecture tests therefore prohibit it for module code, while third-party code remains out of process.

## P1 Review, Nodes, and User Membership

The administrator review page displays the pending artifact identity, hash, signature state, uploader/source, trust level, Manifest, declared capabilities, outbound domains, dependency changes, active-versus-pending differences, and review history. Approval and rejection remain administrator-only POST operations protected by the existing node authorization and CSRF layers.

Module node synchronization is owned by the module lifecycle. Install/activation/enable synchronizes menu and node ownership. Disable/uninstall hides or removes only nodes owned by that module. A module cannot claim host nodes or another module's nodes.

`user_account.source_module` remains immutable registration attribution. A new `user_module_membership` table represents access to one or more modules with status, join source, timestamps, and a unique `(user_id, module)` key. `ModuleApiPolicy` authorizes membership rather than attribution.

Module API registration accepts module identity only from a signed registration ticket or trusted route binding. Account creation, invitation binding, initial membership, and token issuance execute as one transaction. A token failure rolls back the new account and related records.

## P1 Client Protocol Convergence

The versioned Bearer API remains the canonical desktop protocol. Legacy Qingyu client routes emit deprecation headers and structured audit events during a documented compatibility window. Configuration can disable them. Removal occurs only after desktop clients have migrated and telemetry shows no remaining use.

## P2 Contracts, Dependencies, and Persistence

The host supports an explicit set of Manifest schema versions and Gateway contract versions. Unsupported versions fail during staging, before review. Manifest dependencies form a directed graph with cycle detection, topological activation, forward version checks, reverse-dependent checks for disable/uninstall/upgrade, and bidirectional conflict checks.

New migrations add ownership foreign keys where cross-database support is reliable, unique semantic version constraints, state validation at the service layer, and indexes for module/release/log/request retention paths. Existing inconsistent rows are reported by health checks before constraints are applied.

## P2 Operations and Signing

Laravel Scheduler runs notification delivery, balance reconciliation, module health, stale operation recovery, and retention pruning. Commands support machine-readable JSON and continue collecting independent failures instead of exiting on the first one.

Retention policies cover module API requests, refresh/device history, releases, artifacts, module logs, notification history, and completed operation records. Active, pending, rollback-candidate, and legally required audit records are never pruned.

Release signatures include a `key_id`. Configuration exposes a signing key ring with one active key and previous verification keys. Production acceptance fails when no active key is configured. Rotation signs new releases with the active key while old releases remain verifiable until explicitly retired.

## External Worker Contract

The host provides a versioned `ModuleWorkerClient` interface and an HTTP implementation. Requests use HMAC signatures with timestamp and nonce replay protection. The Worker reports protocol version, module release hash, supported operations, and health. The host sends only capability-scoped data and enforces timeouts, quotas, response size, and schema validation.

This repository provides the host contract, configuration, health checks, and a reference Worker protocol test harness. Production execution of partner/community modules remains fail-closed until an independently deployed Worker endpoint passes health and contract verification.

## Error Handling and Observability

Every lifecycle and module API operation has a request or operation ID. Administrator errors are Chinese and actionable; logs retain structured machine fields. Secrets, activation codes, tokens, passwords, and signing keys are never returned by health or audit endpoints.

## Verification

Each delivery follows red-green-refactor testing. Required final gates are PHP syntax checks for changed files, focused unit/feature suites, the complete SQLite suite, migration status on a fresh database, scheduler listing, module health JSON output, route listing, deployment acceptance, handbook consistency checks, and a final Git diff review.

## Non-Goals

This pass does not permit unreviewed code, automatically run production migrations from HTTP, attempt to roll back arbitrary module schema migrations, or claim that in-process capability checks sandbox official PHP modules.
