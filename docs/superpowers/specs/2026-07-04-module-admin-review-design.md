# Module Admin Review Design

## Goal

Add a small administrator review gate before module installation.

This is not a third-party marketplace review system. It is an internal backend approval step controlled by existing EasyAdmin administrator permissions.

## Scope

Supported:

- discovered or uploaded modules can be marked as waiting for review;
- administrators can approve or reject a module before installation;
- rejected modules store a rejection reason in the operation log;
- only approved modules can be installed;
- review actions appear in module logs.

Out of scope:

- public marketplace review;
- signatures;
- remote repository trust;
- multi-reviewer workflow;
- dedicated review tables;
- automatic security scanning.

## State Model

Reuse `system_module.status`.

New statuses:

- `pending_review`: module is known but not approved for install;
- `approved`: module is approved and can be installed;
- `rejected`: module is blocked from install.

Existing statuses stay unchanged:

- `discovered`;
- `installed`;
- `enabled`;
- `disabled`;
- `uninstalled`;
- `failed`.

Install is allowed only from `approved`, `installed`, `enabled`, or `disabled` where the existing lifecycle already permits it. A newly discovered module must be approved before first install.

## Backend Behavior

Discovery:

- `ModuleRepository::upsertDiscovered()` should create new modules as `pending_review`.
- Existing installed/enabled/disabled modules keep their current status when rediscovered.

Zip upload for a new module:

- If the uploaded module is not installed, it should land as `pending_review` or be blocked from direct install until approved.
- Phase 2.1 can keep zip upload upgrade behavior for already installed modules unchanged, because upgrade already requires an installed module and administrator action.

Review actions:

- `approve(name)` changes `pending_review` or `rejected` to `approved`.
- `reject(name, reason)` changes `pending_review` or `approved` to `rejected`.
- Both actions require POST.
- Both actions write `system_module_log` with actions `approve` and `reject`.

Install:

- `ModuleInstaller::install()` rejects first-time install unless status is `approved`.
- After successful install, status becomes `installed` as it does now.

## Backend UI

Module Center list should show the review status through the existing `status` column.

Add row actions:

- Approve: visible for `pending_review` and `rejected`;
- Reject: visible for `pending_review` and `approved`;
- Install: visible only for `approved` or already lifecycle-compatible statuses.

Reject can use a simple prompt for the reason. No separate review page is needed.

## Tests

Add feature coverage for:

- newly discovered module becomes `pending_review`;
- first install is rejected before approval;
- approving a module allows install;
- rejecting a module blocks install and logs the reason;
- review actions reject GET requests;
- Module Center list/action surface includes approve/reject behavior.

## Operations

This keeps the long-term operating model simple:

- module code can be prepared by the internal team or a partner;
- an administrator explicitly approves it in the backend;
- only approved modules can enter the install lifecycle.
