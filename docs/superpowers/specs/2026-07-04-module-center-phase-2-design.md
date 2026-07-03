# EasyAdmin8 Module Center Phase 2 Design

Date: 2026-07-04
Status: Draft for user review

## 1. Goal

Phase 2 turns the Phase 1 local module runtime into an operator-facing Module Center.

It should let an admin manage local modules from the backend:

- view installed/discovered modules;
- inspect manifest, permissions, menus, nodes, database ownership, logs, and errors;
- install, enable, disable, and uninstall-preserve modules;
- upgrade from a changed local module directory;
- upload a zip package and upgrade/install from it;
- roll back code to the previous version, and roll back module migrations only when the module provides a reversible rollback path.

Third-party review, marketplace, remote repositories, commercial licensing, and signature enforcement are not part of Phase 2.

## 2. Existing Base

Phase 1 already provides:

- `system_module`
- `system_module_version`
- `system_module_migration`
- `system_module_log`
- `system_module_source`
- `ModuleManager`
- `ModuleManifest`
- `ModuleRepository`
- `ModuleInstaller`
- `ModuleNodeScanner`
- module routes, views, assets, and runtime autoloading
- lifecycle commands for discover/install/enable/disable/uninstall

Phase 2 should reuse those services and tables. Do not add a new module framework.

## 3. Admin UI

Add one backend controller:

```text
App\Http\Controllers\admin\system\ModuleController
```

Use the existing EasyAdmin8 controller, Blade, Layui, AJAX table, `success()`, and `error()` patterns.

Pages:

- `index`: module table
- `detail`: manifest and runtime detail
- `logs`: lifecycle log list
- `upload`: zip upload/upgrade form

Actions:

- `discover`
- `install`
- `enable`
- `disable`
- `uninstall`
- `upgradeLocal`
- `upgradeZip`
- `rollback`

The menu entry should live under system management and point to:

```text
system/module/index
```

## 4. Module List

The module list should show:

- name
- title
- version
- type
- trust level
- status
- admin prefix
- path
- last error
- installed/enabled/disabled time

Actions should be state-aware:

- discovered: install
- installed: enable, uninstall
- enabled: disable, upgrade, rollback
- disabled: enable, uninstall, upgrade, rollback
- uninstalled: install
- failed: detail, logs

No complex workflow engine. The existing status column is enough.

## 5. Detail Page

The detail page should show data from the current manifest and database row:

- manifest metadata
- requested permissions
- external domains
- declared menus
- scanned permission nodes
- declared database tables
- current installed version
- latest local manifest version
- last error

The page should be read-only except for lifecycle buttons.

## 6. Local Directory Upgrade

Local upgrade is for team modules.

Flow:

1. Admin clicks upgrade on an installed/enabled/disabled module.
2. System reads the module manifest from the existing module path.
3. If manifest version is not greater than installed version, return an error.
4. Backup the current module directory to:

```text
storage/modules/backups/{module}/{version}-{timestamp}
```

5. Run module migrations that have not been recorded in `system_module_migration`.
6. Update `system_module.version` and `config_json`.
7. Insert `system_module_version`.
8. Write `system_module_log` action `upgrade`.
9. Clear module/menu/node caches.

Use semantic-ish version comparison with PHP `version_compare()`. No custom version parser.

## 7. Zip Upgrade and Install

Zip support is Phase 2 because the user requested both local and uploaded upgrades.

Keep it minimal and defensive:

1. Upload zip to a temporary path under `storage/modules/uploads`.
2. Extract into `storage/modules/tmp/{unique}`.
3. Reject zip entries that escape the temp directory.
4. Find `module.json` either at the temp root or one top-level directory below it.
5. Parse manifest with existing `ModuleManifest`.
6. Reject reserved `admin_prefix`.
7. For new modules, move extracted module to `modules/{StudlyName}` and install.
8. For existing modules, require matching `name`; then backup old code and replace the module directory.
9. Run the same upgrade flow as local upgrade.
10. Delete temp files after success or failure.

Do not add signature verification, review status, or remote source records in Phase 2.

## 8. Version History

Use `system_module_version` for version snapshots.

On install and upgrade, write:

- module
- version
- manifest_json
- installed_at
- create_time

If a version row already exists for the same module/version, keep the first row and append a lifecycle log instead of duplicating history.

## 9. Module Migrations

Use `system_module_migration` for module migration tracking.

Migration path comes from manifest `migrations`.

Upgrade should run only migrations not already recorded for that module.

Record:

- module
- migration
- batch
- ran_at

Keep migration execution simple:

- include PHP migration files from the module migrations path;
- instantiate the migration class/object;
- call `up()` during upgrade/install when not recorded;
- call `down()` during rollback only for migrations in the version being rolled back and only if `down()` exists.

If a migration cannot be reversed, rollback should stop before changing code and return a clear error.

## 10. Rollback

Rollback restores the previous code backup and optionally rolls back reversible migrations.

Flow:

1. Admin clicks rollback.
2. System finds the latest backup for the module.
3. System checks rollback migrations for the version being rolled back.
4. If every migration has `down()`, run them in reverse order.
5. Replace current module directory with the backup.
6. Restore `system_module.version` and `config_json` from `system_module_version`.
7. Write `system_module_log` action `rollback`.
8. Clear caches.

Data is preserved by default. No destructive table drops unless the module's own reversible migration explicitly performs them.

## 11. Backup Rules

Backups are plain directory copies. No compression in Phase 2.

Location:

```text
storage/modules/backups/{module}/{version}-{timestamp}
```

Keep all backups. Retention policy is not Phase 2.

## 12. Error Handling

All lifecycle actions should:

- run in a database transaction where database writes are involved;
- write `system_module_log`;
- update `system_module.last_error` on failure;
- leave the previous module directory in place if validation fails before replacement;
- restore from backup if replacement fails after backup.

Zip temp files should be deleted in `finally` blocks.

## 13. Tests

Add focused tests for:

- admin list JSON returns modules;
- install/enable/disable/uninstall actions call existing lifecycle services;
- local upgrade rejects same/lower versions;
- local upgrade records `system_module_version`;
- zip extraction rejects path traversal;
- zip upgrade rejects mismatched module names;
- zip upgrade replaces code after manifest validation;
- rollback restores previous version metadata;
- rollback refuses irreversible migrations;
- full suite still passes through `composer run test:sqlite`.

## 14. Non-Goals

Phase 2 will not include:

- third-party manual review;
- signature verification;
- module marketplace;
- remote repository sync;
- auto-update scheduling;
- paid licensing;
- full PHP sandboxing;
- destructive uninstall;
- backup retention cleanup.

## 15. Success Criteria

Phase 2 is complete when:

- admins can manage local modules from the backend Module Center;
- local directory upgrades work and are logged;
- zip install/upgrade works with path safety checks;
- version history is recorded;
- module migrations are tracked;
- rollback restores code and metadata;
- irreversible migration rollback is blocked safely;
- Phase 1 runtime behavior remains compatible.
