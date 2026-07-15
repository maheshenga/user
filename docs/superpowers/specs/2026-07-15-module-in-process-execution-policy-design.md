# Module In-Process Execution Policy Design

## Goal

Prevent third-party module code from running with the Laravel host process privileges in production while preserving the existing upload, manual review, immutable release, and installation workflows.

## Security Boundary

Laravel service providers, controllers, migrations, and bootstrappers execute with the same filesystem, database, network, secrets, and container access as the host application. Manifest permissions and approved external domains describe intended access but do not create a runtime sandbox.

Production therefore permits in-process execution only for trusted module levels configured by the host. The default allowlist is `core`, `official`, and `private`. The `partner` and `community` levels may still be uploaded, reviewed, staged, and retained, but they cannot be enabled or loaded inside the host process.

Running third-party code requires a future isolated module Worker/service with separate operating-system credentials and an authenticated API boundary. That service is outside this repository and outside this change.

## Policy Component

`App\Modules\ModuleExecutionPolicy` owns the environment and trust-level decision:

- `isInProcessAllowed(SystemModule $module): bool` returns the decision without side effects.
- `assertInProcessAllowed(SystemModule $module): void` throws an explicit Chinese `InvalidArgumentException` when production blocks the module.
- Non-production environments retain current behavior for local development and automated tests.
- Production reads `modules.production_in_process_trust_levels`; an absent, malformed, or empty allowlist permits no trust level.
- The decision uses the persisted `system_module.trust_level`, falling back to `type` only for legacy rows with an empty trust level.

## Enforcement Points

`ModuleInstaller::enable()` calls the policy after confirming the row exists and its lifecycle state is enableable, but before release parsing, menu synchronization, or the status transition. Failed attempts continue through the existing lifecycle error and audit logging path.

`ModuleManager::manifestFromRow()` calls the same policy before release integrity checks or manifest loading. This protects boot-time registration and request-time module discovery even when a stale or manually edited database row is already marked `enabled`. A rejected row is omitted and its `last_error` records the policy message.

These two checks cover module providers, routes, views, assets, node scanning, and prefix dispatch because those consumers all depend on `ModuleManager::enabled()` or `enabledByPrefix()`.

## Compatibility

- The internal `qingyu_ip_agent` module is `private` and remains loadable.
- Existing production immutable-release and signature checks remain mandatory after the execution policy allows a module.
- Local and testing environments continue to load all currently supported module types.
- Review and release records are not changed, so no migration is required.
- Hosts may narrow the allowlist through configuration but should not add `partner` or `community` until a genuine external execution boundary exists.

## Testing

- Production rejects enabling `community` and `partner` modules with the policy error.
- Production permits a valid immutable `private` module to enable.
- Production runtime discovery omits a stale enabled `community` row and records `last_error`.
- Non-production policy evaluation allows a `community` module for development compatibility.
- Existing module release, runtime, and Qingyu module tests remain green.

## Operational Notes

Before deployment, configuration cache must be rebuilt so the new default allowlist is active. Existing production rows marked `enabled` with `partner` or `community` are fail-closed at the next module discovery; operators should disable those rows and migrate them to an isolated Worker before reactivation.
