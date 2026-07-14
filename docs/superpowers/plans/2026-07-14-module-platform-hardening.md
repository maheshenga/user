# Module Platform Hardening Implementation Plan

> **For agentic workers:** Execute this plan inline and task-by-task. Do not dispatch subagents. Every behavior change uses red-green-refactor and each task ends with focused verification.

**Goal:** Implement immutable module releases, version-bound administrator review, module data ownership, lifecycle-bound API tokens, stable host contracts, and reliable module API request handling.

**Architecture:** Approved module versions are immutable release artifacts referenced by database pointers. The host validates and signs releases, injects module context into shared user-domain gateways, and applies lifecycle policy to menus, API sessions, and module-owned data. Qingyu remains the first reference module and the desktop keeps its existing IPC names.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, Sanctum, SQLite/MySQL migrations, Electron/Node.js.

## Global Constraints

- Keep existing EasyAdmin dynamic controller, view, and asset conventions.
- Keep desktop `user:*`, `video-parser:*`, and `llm:*` IPC channel names stable.
- Do not expose access tokens, refresh tokens, provider keys, or signing keys to the renderer.
- Third-party in-process PHP modules are trusted only after administrator review of an immutable artifact.
- Qingyu does not add an invitation UI, but may consume the shared host invitation APIs.
- Preserve existing user and module data on uninstall.

---

### Task 1: Immutable Release Registry And Manifest Policy

**Files:**
- Create `database/migrations/2026_07_14_000002_create_module_release_hardening_tables.php`
- Create `app/Models/SystemModuleRelease.php`
- Create `app/Models/SystemModuleMenu.php`
- Create `app/Modules/ModuleArtifactHasher.php`
- Create `app/Modules/ModuleArtifactStore.php`
- Create `app/Modules/ModuleManifestPolicy.php`
- Create `app/Modules/ModuleReleaseSigner.php`
- Extend `app/Modules/ModuleManifest.php`
- Test in `tests/Unit/Modules/ModuleManifestTest.php` and `tests/Feature/Modules/ModuleReleaseTest.php`

- [ ] Write tests that reject unsupported types, versions, capabilities, host/PHP constraints, dependencies, and conflicts.
- [ ] Run the focused tests and confirm they fail for the missing policy and schema.
- [ ] Add release/menu schema, artifact hashing/copying, manifest accessors, validation, and signing.
- [ ] Run focused tests and confirm immutable staged artifacts and policy checks pass.

### Task 2: Version-Bound Review, Activation, And Rollback

**Files:**
- Create `app/Modules/ModuleReleaseManager.php`
- Create `app/Modules/ModuleReviewService.php`
- Modify `app/Modules/ModuleInstaller.php`
- Modify `app/Modules/ModuleUpgrader.php`
- Modify `app/Modules/ModuleRollbacker.php`
- Modify `app/Modules/ModuleRepository.php`
- Modify `app/Modules/ModuleManager.php`
- Modify `app/Http/Controllers/admin/system/ModuleController.php`
- Modify `public/static/admin/js/system/module.js`
- Test in module lifecycle, upgrade, rollback, and controller suites.

- [ ] Write tests proving ZIP upload does not alter an enabled module, every version requires approval, and local production upgrades are rejected.
- [ ] Run tests and confirm the current immediate-upgrade behavior fails them.
- [ ] Stage uploads as releases, approve/reject exact hashes, activate approved releases by pointer switch, and roll back by release history.
- [ ] Run focused lifecycle tests and verify old/new version logging and failed-activation restoration.

### Task 3: Migration Compensation And Managed Menus

**Files:**
- Create `app/Modules/ModuleMenuSynchronizer.php`
- Modify `app/Modules/ModuleMigrationRunner.php`
- Modify `app/Modules/ModuleInstaller.php`
- Test in `ModulePhase2LifecycleTest.php`, `ModuleLifecycleTest.php`, and a new menu ownership test.

- [ ] Write tests for compensating earlier migrations when a later migration fails and for hiding/restoring only module-owned menus.
- [ ] Run tests and confirm missing compensation and ownership fail.
- [ ] Implement migration compensation and menu reconciliation through `system_module_menu`.
- [ ] Run focused tests and verify install, enable, disable, upgrade, and uninstall behavior.

### Task 4: Qingyu Data Ownership

**Files:**
- Modify the hardening migration to add `owner_module` indexes.
- Modify `app/User/ActivationCodeService.php`.
- Modify Qingyu member, dashboard, activation, and client services.
- Test in `tests/Feature/Modules/QingyuIpAgentModuleTest.php`.

- [ ] Write tests with users and activation codes from two modules and prove Qingyu currently leaks cross-module data.
- [ ] Run tests and confirm they fail.
- [ ] Enforce `source_module` and `owner_module` in service queries and redemption.
- [ ] Run focused Qingyu tests and confirm module isolation.

### Task 5: Module API Lifecycle Policy And Host Contracts

**Files:**
- Create module context and gateway contracts under `app/Contracts/Modules`.
- Create host adapters under `app/Modules/Host`.
- Create `app/User/ModuleApiPolicy.php` and `app/Http/Middleware/RequireActiveApiModule.php`.
- Modify token service, bootstrap middleware aliases, routes, installer, and Qingyu service dependencies.
- Add bearer `me` endpoints for VIP, invitations, balance, and ledger.

- [ ] Write tests that disabled modules cannot issue, refresh, or use tokens and that disabling revokes sessions.
- [ ] Write contract binding and bearer `me` endpoint tests.
- [ ] Implement manifest-derived abilities, lifecycle middleware, revocation, gateway bindings, and read-only user APIs.
- [ ] Run token, route, and Qingyu integration tests.

### Task 6: Idempotency, Quotas, Typed Errors, And Desktop Timeouts

**Files:**
- Extend the hardening migration with `module_api_request` and Qingyu audit context columns.
- Create `app/Modules/ModuleApiRequestService.php` and `app/Modules/ModuleApiException.php`.
- Modify Qingyu API controller and audit service.
- Modify `E:/code/aigc-human/desktop-shell/takeover-core.js` and `takeover.js`.
- Extend Laravel and Node tests.

- [ ] Write tests for duplicate request replay, in-progress conflicts, daily quota errors, stable error codes, and request IDs.
- [ ] Write desktop tests for 35-second parser and 60-second rewrite budgets with stable request IDs across refresh retry.
- [ ] Implement request persistence, limits, typed errors, audit correlation, and operation-specific timeouts.
- [ ] Run focused backend and desktop tests.

### Task 7: Documentation, Review, Verification, And Deployment

**Files:**
- Update `docs/modules/ai-module-development-handbook.md`.
- Update Qingyu changelog and review documentation.
- Add module release and API health checks to deployment acceptance scripts.

- [ ] Update the handbook from session-only `/user/*` guidance to the stable host gateway and `/api/v1` contracts.
- [ ] Review the complete diff for security, migration, rollback, compatibility, and secret handling.
- [ ] Run the complete Laravel and desktop test suites plus syntax and route checks.
- [ ] Commit, push `main`, deploy migrations/code, reload services, and run public smoke checks.

