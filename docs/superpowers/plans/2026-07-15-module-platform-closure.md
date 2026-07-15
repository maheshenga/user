# Module Platform Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. This plan is executed inline without subagents.

**Goal:** Close the audited safety, lifecycle, authorization, user ownership, contract, and operations gaps in the existing module platform without replacing its working release model.

**Architecture:** Preserve the current Manifest and immutable release pipeline. Add small shared policy/state services at trust boundaries, move module access from registration attribution to memberships, and keep third-party execution out of process through a signed Worker client contract.

**Tech Stack:** PHP 8.3, Laravel 12, Eloquent, Sanctum, SQLite/MySQL-compatible migrations, Blade, Layui, PHPUnit 12.

## Global Constraints

- Internal `core`, `official`, and `private` modules may run in process only after current release checks pass.
- `partner` and `community` PHP must never execute in the Laravel process.
- Administrator review remains mandatory and administrator-only.
- `user_account.source_module` is immutable attribution, not an authorization boundary.
- Module identity, ownership, and audit source are derived from trusted context, never request payloads.
- All production behavior changes use red-green-refactor tests.
- Database migrations are explicit deployment operations and are not triggered by web requests.

---

### Task 1: P0 portable baseline and safe administration filters

**Files:**
- Modify: `.env.example`, `.env.production.example`, `.example.env`, `phpunit.xml`
- Modify: `app/Http/Controllers/common/AdminController.php`
- Modify: `app/Http/Controllers/admin/system/ModuleController.php`
- Modify: `scripts/deploy-acceptance.php`
- Test: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Test: `tests/Feature/User/DeployAcceptanceScriptTest.php`

**Interfaces:**
- `AdminController::buildTableParams(array $excludeFields = [], array $allowedFields = []): array`
- `AdminController::sanitizeFilterIdentifier(string $field): ?string`
- Module list fields: `name`, `display_name`, `version`, `type`, `status`, `admin_prefix`, `last_error`, timestamps.
- Module log fields: `action`, `module`, `old_state`, `new_state`, `result`, `actor_id`, timestamps.

- [ ] Write controller tests that submit injected filter keys, `in` values, `find_in_set` values, invalid operators, and injected sort values; assert no injected SQL executes and valid filters still work.
- [ ] Run `ModuleCenterControllerTest` and confirm the new tests fail against request-driven `DB::raw`.
- [ ] Replace `PRC` with `Asia/Shanghai` in tracked runtime/test templates.
- [ ] Validate filter identifiers and explicit ModuleController allowlists; normalize `in` and `find_in_set` to integer-only expressions and restrict operators to the existing supported set.
- [ ] Validate module list/log sort columns and directions.
- [ ] Extend deployment acceptance to detect pending migrations, missing release schema/signing configuration, and route-cache drift without printing secrets.
- [ ] Run the two focused test files and `git diff --check`.
- [ ] Commit as `fix: secure module deployment baseline`.

### Task 2: Shared runtime eligibility and atomic registration

**Files:**
- Create: `app/Modules/ModuleRuntimeEligibility.php`
- Modify: `app/Modules/ModuleManager.php`
- Modify: `app/User/ModuleApiPolicy.php`
- Modify: `app/Http/Middleware/RequireActiveApiModule.php`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `app/User/UserAuthService.php`
- Modify: `app/User/UserApiTokenService.php`
- Test: `tests/Feature/Modules/ModuleRuntimeTest.php`
- Test: `tests/Feature/User/UserApiTokenAuthTest.php`
- Test: `tests/Feature/User/UserAuthTest.php`

**Interfaces:**
- `ModuleRuntimeEligibility::assertEligible(string|SystemModule $module, bool $verifyIntegrity = false): SystemModule`
- `UserAuthService::registerWithToken(array $attributes, array $tokenAttributes, ?callable $afterAccount = null): array{user: UserAccount, token: array}`

- [ ] Add failing tests for enabled modules with invalid releases/signatures and for token registration rollback when abilities or module eligibility fail.
- [ ] Delegate `ModuleManager` and `ModuleApiPolicy` availability checks to `ModuleRuntimeEligibility`.
- [ ] Wrap account creation, invitation relation, membership callback, and token issuance in one database transaction.
- [ ] Run the three focused suites and commit as `fix: unify module runtime eligibility`.

### Task 3: Module execution context and enforced Gateway capabilities

**Files:**
- Create: `app/Modules/ModuleExecutionContext.php`
- Create: `app/Modules/ModuleCapabilityPolicy.php`
- Create: `app/Http/Middleware/EstablishModuleExecutionContext.php`
- Modify: `bootstrap/app.php`, `config/modules.php`, `app/Providers/AppServiceProvider.php`
- Modify: all files in `app/Modules/Host/`
- Modify: Gateway contracts in `app/Contracts/Modules/`
- Test: `tests/Feature/Modules/ModuleCapabilityTest.php`
- Test: `tests/Feature/Modules/QingyuIpAgentModuleTest.php`

**Interfaces:**
- `ModuleExecutionContext::run(SystemModule $module, string $requestId, callable $callback): mixed`
- `ModuleExecutionContext::requireCurrent(): ModuleIdentity`
- `ModuleCapabilityPolicy::authorize(string $capability, ?string $ownedModule = null): ModuleIdentity`
- `ModuleExecutionContext::runAsHost(callable $callback): mixed` for explicit host-owned maintenance only.

- [ ] Add failing tests proving missing context, missing capability, spoofed source module, and cross-module ownership are rejected.
- [ ] Establish context after module eligibility middleware using the authenticated token module and active release.
- [ ] Enforce least-privilege capability checks in each Host Gateway and derive source/audit fields from context.
- [ ] Update Qingyu Manifest declarations and service calls to use the Gateway contract where a host capability exists.
- [ ] Run module capability and Qingyu suites; commit as `feat: enforce module gateway capabilities`.

### Task 4: Lifecycle lock, operation state, and stale recovery

**Files:**
- Create: `database/migrations/2026_07_15_000005_create_module_operation_records.php`
- Create: `app/Models/SystemModuleOperation.php`
- Create: `app/Modules/ModuleOperationCoordinator.php`
- Create: `app/Modules/ModuleOperationRecovery.php`
- Modify: `app/Modules/ModuleReleaseManager.php`, `ModuleReviewService.php`, `ModuleInstaller.php`, `ModuleUpgrader.php`, `ModuleRollbacker.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Modules/ModuleOperationStateTest.php`
- Test: `tests/Feature/Modules/ModuleReleaseTest.php`
- Test: `tests/Feature/Modules/ModuleRollbackTest.php`

**Interfaces:**
- `ModuleOperationCoordinator::run(string $module, string $action, ?int $actorId, callable $operation): mixed`
- `ModuleOperationCoordinator::stage(string $operationId, string $stage): void`
- `ModuleOperationRecovery::recoverStale(CarbonInterface $before): array`

- [ ] Add failing concurrency/state tests for double staging, approve-versus-reject, activate-versus-disable, and stale `upgrading` recovery.
- [ ] Acquire one cache/file lock per module and `lockForUpdate()` the module row before state transitions.
- [ ] Persist operation IDs, stages, heartbeat, final result, recoverable state, actor, and redacted errors.
- [ ] Add `system:module-operations:recover` with human and JSON output.
- [ ] Run lifecycle/release/rollback suites and commit as `feat: make module lifecycle recoverable`.

### Task 5: Complete administrator review and automatic node ownership

**Files:**
- Create: `database/migrations/2026_07_15_000006_add_module_node_ownership.php`
- Create: `app/Modules/ModuleNodeSynchronizer.php`
- Modify: `app/Modules/ModuleInstaller.php`, `ModuleReleaseManager.php`, `ModuleRollbacker.php`
- Modify: `app/Http/Controllers/admin/system/ModuleController.php`
- Modify: `resources/views/admin/system/module/detail.blade.php`
- Modify: `public/static/admin/js/system/module.js`
- Test: `tests/Feature/Modules/ModuleCenterControllerTest.php`
- Test: `tests/Feature/Modules/ModuleNodeOwnershipTest.php`

**Interfaces:**
- `ModuleNodeSynchronizer::sync(ModuleManifest $manifest): int`
- `ModuleNodeSynchronizer::hide(string $module): int`
- Review detail payload includes active/pending manifests, capability/domain/dependency diffs, hashes, signature state, source, uploader, reviewer, reason, and history.

- [ ] Add failing tests for review detail completeness and cross-module node claims.
- [ ] Render pending artifact details and structured active/pending differences with escaped output.
- [ ] Synchronize owned nodes during activation/enable and hide them during disable/uninstall.
- [ ] Run focused controller/node suites and commit as `feat: close module review and node ownership`.

### Task 6: Multi-module user membership and trusted registration tickets

**Files:**
- Create: `database/migrations/2026_07_15_000007_create_user_module_memberships.php`
- Create: `app/Models/UserModuleMembership.php`
- Create: `app/User/UserModuleMembershipService.php`
- Create: `app/User/ModuleRegistrationTicketService.php`
- Modify: `app/Models/UserAccount.php`, `app/User/ModuleApiPolicy.php`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`, `routes/api.php`
- Test: `tests/Feature/User/UserModuleMembershipTest.php`
- Test: `tests/Feature/User/UserAuthTest.php`
- Test: `tests/Feature/User/UserApiTokenAuthTest.php`

**Interfaces:**
- `UserModuleMembershipService::grant(int $userId, string $module, string $joinSource, ?int $actorId = null): UserModuleMembership`
- `UserModuleMembershipService::assertActive(int $userId, string $module): UserModuleMembership`
- `ModuleRegistrationTicketService::issue(string $module, array $claims, CarbonInterface $expiresAt): string`
- `ModuleRegistrationTicketService::consume(string $ticket): array`

- [ ] Add failing tests for one user joining two modules, attribution immutability, ticket replay/expiry/tampering, and rollback after token failure.
- [ ] Backfill active memberships from non-null `source_module`; keep the attribution column unchanged.
- [ ] Require trusted route binding or single-use signed ticket for module registration.
- [ ] Authorize API use through active memberships and issue scoped tokens inside the registration transaction.
- [ ] Run user auth/token/membership suites and commit as `feat: add module user memberships`.

### Task 7: Legacy Qingyu protocol deprecation

**Files:**
- Create: `app/Http/Middleware/LegacyModuleClientDeprecation.php`
- Modify: `modules/QingyuIpAgent/routes/api.php`, `config/modules.php`
- Modify: `modules/QingyuIpAgent/src/Controllers/ClientController.php`
- Modify: `docs/modules/ai-module-development-handbook.md`
- Test: `tests/Feature/Modules/QingyuIpAgentModuleTest.php`

**Interfaces:**
- Legacy responses include `Deprecation: true`, `Sunset`, and a canonical `Link` header.
- `MODULE_LEGACY_CLIENT_ROUTES_ENABLED=false` returns HTTP 410 with a versioned API migration message.

- [ ] Add failing tests for deprecation headers, audit event, kill switch, and canonical route link.
- [ ] Register middleware only on legacy routes and document the sunset process.
- [ ] Run Qingyu module suite and commit as `fix: deprecate legacy Qingyu client routes`.

### Task 8: Manifest/Gateway versioning and dependency graph safety

**Files:**
- Create: `app/Modules/ModuleContractRegistry.php`
- Create: `app/Modules/ModuleDependencyGraph.php`
- Modify: `app/Modules/ModuleManifest.php`, `ModuleManifestPolicy.php`, `ModuleInstaller.php`, `ModuleReleaseManager.php`
- Modify: `config/modules.php`, `modules/QingyuIpAgent/module.json`
- Test: `tests/Unit/Modules/ModuleManifestTest.php`
- Test: `tests/Feature/Modules/ModuleDependencyGraphTest.php`

**Interfaces:**
- `ModuleContractRegistry::assertManifestSupported(ModuleManifest $manifest): void`
- `ModuleDependencyGraph::activationOrder(string $module): array`
- `ModuleDependencyGraph::assertCanDisable(string $module): void`
- `ModuleDependencyGraph::assertUpgradeCompatible(ModuleManifest $candidate): void`

- [ ] Add failing tests for unsupported schema/Gateway versions, dependency cycles, reverse dependent blocking, and bidirectional conflicts.
- [ ] Validate contract versions during discovery/staging and perform graph checks before lifecycle mutations.
- [ ] Run manifest/dependency suites and commit as `feat: version module contracts and dependencies`.

### Task 9: Signing key ring, retention, scheduler, and health aggregation

**Files:**
- Create: `database/migrations/2026_07_15_000008_add_release_signing_key_and_ops_indexes.php`
- Create: `app/Modules/ModuleRetentionService.php`
- Create: `app/Modules/ModuleHealthInspector.php`
- Modify: `app/Modules/ModuleReleaseSigner.php`, `config/modules.php`, environment templates
- Modify: `routes/console.php`, `routes/api.php` or health command registration only
- Modify: `scripts/deploy-acceptance.php`
- Test: `tests/Feature/Modules/ModuleOperationsTest.php`
- Test: `tests/Feature/Modules/ModuleReleaseTest.php`
- Test: `tests/Feature/User/DeployAcceptanceScriptTest.php`

**Interfaces:**
- `ModuleReleaseSigner::sign(SystemModuleRelease $release): string` records active `key_id`.
- `ModuleReleaseSigner::verify(SystemModuleRelease $release): bool` selects a verification key by `key_id`.
- `ModuleHealthInspector::inspect(): array{ok: bool, issues: array, metrics: array}`
- `ModuleRetentionService::prune(CarbonInterface $before, int $limit): array`

- [ ] Add failing tests for key rotation, unknown key IDs, aggregated health issues, protected release retention, and scheduler registration.
- [ ] Add active/previous key-ring configuration with legacy single-key compatibility outside production.
- [ ] Register notification dispatch, reconciliation, health, stale recovery, and retention schedules with overlap protection.
- [ ] Add JSON output to health and retention commands and ensure all independent issues are collected.
- [ ] Run operations/release/deployment suites and commit as `feat: operationalize module platform maintenance`.

### Task 10: External Worker host contract and documentation alignment

**Files:**
- Create: `app/Contracts/Modules/ModuleWorkerClient.php`
- Create: `app/Modules/Worker/HttpModuleWorkerClient.php`
- Create: `app/Modules/Worker/ModuleWorkerRequestSigner.php`
- Create: `app/Modules/Worker/ModuleWorkerEligibility.php`
- Create: `tests/Feature/Modules/ModuleWorkerContractTest.php`
- Modify: `app/Modules/ModuleExecutionPolicy.php`, `ModuleRuntimeEligibility.php`, `config/modules.php`, `app/Providers/AppServiceProvider.php`
- Modify: `docs/modules/ai-module-development-handbook.md`
- Modify: `modules/QingyuIpAgent/module.json`

**Interfaces:**
- `ModuleWorkerClient::health(): array`
- `ModuleWorkerClient::invoke(ModuleIdentity $identity, string $operation, array $payload, string $requestId): array`
- Signed headers: protocol version, key ID, timestamp, nonce, request ID, module, release hash, body hash, signature.

- [ ] Add failing HTTP-fake tests for healthy/incompatible Workers, bad signatures, timeout, replay metadata, response size, and fail-closed production activation.
- [ ] Bind the HTTP Worker client and allow partner/community eligibility only when the configured Worker attests to protocol and release compatibility.
- [ ] Keep third-party PHP excluded from the Laravel provider/route/autoload scanners.
- [ ] Reconcile handbook rules for Gateways, registration tickets, external domains, API versions, capabilities, and Worker execution.
- [ ] Run Worker/execution policy/handbook tests and commit as `feat: add external module worker contract`.

### Task 11: Final migration, review, and release gate

**Files:**
- Review every file changed since the design commit.

- [ ] Run PHP syntax checks over all changed PHP files.
- [ ] Run all module and user feature/unit suites.
- [ ] Run the complete SQLite suite and retain the command result.
- [ ] Build a fresh SQLite database, run all migrations, verify no pending migrations, list routes and scheduler entries, then run module health JSON and deployment acceptance.
- [ ] Search for request-controlled `DB::raw`, direct Qingyu host service/model access, unsupported `PRC`, unversioned Gateway declarations, and leaked signing secrets.
- [ ] Review `git diff --stat`, `git diff --check`, and the full diff for security, compatibility, migration reversibility, and accidental generated files.
- [ ] Fix every Critical/Important review finding and rerun affected tests.
- [ ] Commit final review corrections as `chore: review module platform closure` only when corrections exist.
- [ ] Merge the verified branch into `main` with a non-interactive Git command and report the final commit IDs.
