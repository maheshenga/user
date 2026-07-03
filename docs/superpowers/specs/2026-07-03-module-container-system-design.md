# EasyAdmin8 Module Container System Design

Date: 2026-07-03
Status: Draft for user review

## 1. Purpose

EasyAdmin8 currently has a convention-based admin module shape:

- Admin URL: `/admin/{module}/{controller}/{action}`
- Controller: `app/Http/Controllers/admin/{module}/{Controller}Controller.php`
- View: `resources/views/admin/{module}/{controller}/{action}.blade.php`
- JavaScript: `public/static/admin/js/{module}/{controller}.js`
- Menu: `system_menu.href`
- Permission node: `system_node.node`

This works well for built-in modules such as `system` and `mall`, but the module is not a portable unit. Code, views, assets, SQL, menus, and permission nodes are spread across the host application.

The goal is to introduce a module container system that lets the EasyAdmin8 team and approved third-party developers create independent modules that can be installed, enabled, disabled, upgraded, audited, and safely operated over the long term.

## 2. Operating Model

The module system serves two audiences:

- Internal/team modules: fast development, low friction, first-party trust.
- Third-party modules: manually reviewed, signed by the platform, installed only after compatibility and permission checks.

The same runtime container should load both types. The difference is trust policy, not technical structure.

## 3. Trust Levels

Modules are classified by source:

- `core`: shipped with EasyAdmin8, required by the system, cannot be uninstalled.
- `official`: built by the EasyAdmin8 team, trusted, can be auto-upgraded by policy.
- `partner`: built by approved partners, requires signature and compatibility checks.
- `community`: third-party modules, manually reviewed and platform-signed before publication.
- `private`: project-local modules, visible only in the current installation.

Production environments should reject unsigned `partner` and `community` modules. Development environments may allow unsigned `private` modules.

## 4. Module Package Layout

Recommended module layout:

```text
modules/
  Mall/
    module.json
    src/
      Providers/MallServiceProvider.php
      Controllers/GoodsController.php
      Controllers/OrderController.php
      Models/Goods.php
      Services/OrderService.php
      Events/OrderCreated.php
      Listeners/AddMemberPoint.php
    resources/
      views/
        goods/index.blade.php
        order/index.blade.php
      lang/
        zh_CN/messages.php
    assets/
      js/goods.js
      js/order.js
      css/mall.css
    database/
      migrations/
      seeders/
    config/
      mall.php
    docs/
      README.md
      CHANGELOG.md
```

This layout keeps module assets together and avoids copying module files into host directories.

## 5. Manifest Contract

Every module must include `module.json`. This is the module's stable contract with the container.

Example:

```json
{
  "schema_version": "1.0",
  "name": "mall",
  "title": "商城模块",
  "vendor": "easyadmin8",
  "version": "1.0.0",
  "type": "official",
  "core_version": "^8.0",
  "php": ">=8.3",
  "namespace": "Modules\\Mall",
  "entry": "src/Providers/MallServiceProvider.php",
  "admin_prefix": "mall",
  "controllers": "src/Controllers",
  "views": "resources/views",
  "assets": "assets",
  "migrations": "database/migrations",
  "seeders": "database/seeders",
  "permissions": [
    "database:migrate",
    "menu:write",
    "node:write",
    "config:write"
  ],
  "external_domains": [],
  "dependencies": {},
  "conflicts": {},
  "database": {
    "tables": ["mall_goods", "mall_cate"],
    "preserve_on_uninstall": true
  },
  "menus": [
    {
      "title": "商城管理",
      "icon": "fa fa-store",
      "children": [
        {
          "title": "商品管理",
          "icon": "fa fa-box",
          "href": "mall/goods/index"
        }
      ]
    }
  ]
}
```

Required fields:

- `schema_version`
- `name`
- `title`
- `vendor`
- `version`
- `type`
- `core_version`
- `namespace`
- `admin_prefix`

The manifest is also the basis for review, signature, install prompts, dependency checks, and future marketplace metadata.

## 6. Runtime Components

The container should add these services:

```text
ModuleManager
  Discovers modules, reads manifests, exposes enabled module metadata.

ModuleManifest
  Validates and normalizes module.json.

ModuleRepository
  Persists installed module state in database tables.

ModuleRouteResolver
  Resolves /admin/{module}/{controller}/{action} to module controllers first,
  then falls back to the existing App\Http\Controllers\admin namespace.

ModuleViewRegistrar
  Registers module Blade view namespaces.

ModuleAssetController
  Serves module assets through a controlled route such as
  /module-assets/{module}/{path}.

ModuleNodeScanner
  Scans both host admin controllers and enabled module controllers for
  ControllerAnnotation and NodeAnnotation attributes.

ModuleInstaller
  Installs, enables, disables, upgrades, rolls back, and uninstalls modules.

ModuleEventBus
  Provides explicit events for cross-module extension without direct coupling.
```

## 7. Routing Strategy

The current dynamic route should remain compatible.

Resolution order:

1. Read `{secondary}` from the admin URL.
2. Ask `ModuleManager` whether an enabled module owns this admin prefix.
3. If yes, resolve the controller under the module namespace.
4. If not found, fall back to the existing controller namespace:
   `App\Http\Controllers\admin\{secondary}`.
5. If neither exists, return 404.

Example:

```text
/admin/mall/goods/index
  -> Modules\Mall\Controllers\GoodsController@index
  -> fallback: App\Http\Controllers\admin\mall\GoodsController@index
```

This preserves existing modules while allowing independent module packages.

## 8. Views and Assets

For enabled modules, the container registers a view namespace:

```text
modules.mall::goods.index
```

`AdminController::fetch()` should prefer module views when the current `secondary` belongs to an enabled module, then fall back to the existing `admin.mall.goods.index` convention.

JavaScript loading should support module assets:

```text
/module-assets/mall/js/goods.js
```

The existing RequireJS autoload behavior can remain. The backend should inject the correct controller JS path:

- Existing module: `admin/js/mall/goods.js`
- Container module: `module-assets/mall/js/goods.js`

## 9. Menu and Permission Nodes

Module installation imports declared menus into `system_menu`.

Node scanning must include:

- `app/Http/Controllers/admin`
- `modules/{Module}/src/Controllers`

Nodes still use the same string format:

```text
mall/goods/index
mall/goods/stock
```

This keeps `AuthService::checkNode()` and existing Blade helpers such as `auths()` compatible.

Module enable, disable, install, uninstall, and upgrade must invalidate:

- menu cache
- node cache
- system/module config cache

## 10. Database and Migrations

Module migrations must be tracked separately from host migrations.

Recommended tables:

```text
ea8_system_module
ea8_system_module_version
ea8_system_module_migration
ea8_system_module_log
ea8_system_module_source
```

`ea8_system_module` stores current installation state:

```text
id
name
title
vendor
version
type
trust_level
status
path
namespace
admin_prefix
signature_hash
installed_at
enabled_at
disabled_at
last_error
config_json
```

Uninstall should default to preserving module data. Destructive uninstall must be explicit and logged.

## 11. Lifecycle

Module states:

```text
discovered
installed
enabled
disabled
upgrading
failed
uninstalled
```

Supported actions:

```text
discover
install
enable
disable
upgrade
rollback
uninstall
```

Each action writes a module log entry with:

- actor/admin id
- module name
- old state
- new state
- old version
- new version
- started_at
- finished_at
- result
- error message when failed

## 12. Third-Party Review and Signing

Third-party modules are not installed directly from developer-submitted zips in production.

Review flow:

```text
developer submits module package
-> automated checks
-> manual review
-> approval
-> platform signature
-> publish to module repository
-> user installs from module center
```

Automated checks should verify:

- manifest validity
- file layout
- version compatibility
- declared permissions
- declared external domains
- migration safety patterns
- forbidden or risky PHP functions
- remote scripts in frontend assets
- unexpected writes outside module-owned paths

Manual review should verify:

- no hidden routes or backdoors
- no bypass of admin permission nodes
- no modification of non-owned data without clear declaration
- no undeclared external network calls
- no obfuscated frontend JavaScript
- uninstall preserves data by default
- README and CHANGELOG are present

After approval, the platform signs the package.

Signature metadata:

```json
{
  "module": "sms-notify",
  "version": "1.0.0",
  "hash": "sha256:...",
  "signed_by": "easyadmin8-official",
  "signed_at": "2026-07-03T12:00:00Z",
  "review_id": "REV-20260703-001"
}
```

Production install requires a valid platform signature for `partner` and `community` modules.

## 13. Module Center UI

The admin backend should add a Module Center with tabs:

- Installed
- Available
- Updates
- Sources
- Logs
- Security Policy

Module detail page should show:

- module metadata
- version
- developer/vendor
- trust level
- signature status
- review status
- permissions requested
- external domains
- menus to be added
- nodes to be registered
- database tables owned
- install/upgrade/uninstall logs

Before installation, the UI must show a risk summary:

```text
This module will:
- create 2 database tables
- register 8 permission nodes
- add 1 menu group
- write module configuration
- access api.sms-provider.com
```

## 14. Events and Extension Points

The container should expose events for cross-module communication:

```text
module.installed
module.enabled
module.disabled
module.upgraded
module.uninstalled
```

Business modules can also emit domain events:

```text
mall.order.created
mall.goods.updated
member.registered
```

Modules should depend on events and service contracts, not directly on another module's internal classes.

## 15. Compatibility Strategy

The module system must not break existing EasyAdmin8 modules.

Compatibility rules:

1. Existing controllers under `app/Http/Controllers/admin` keep working.
2. Existing views under `resources/views/admin` keep working.
3. Existing assets under `public/static/admin/js` keep working.
4. Existing menus and nodes keep the same string format.
5. New modules are resolved before the old fallback only when the module is enabled.

This allows gradual migration from built-in modules to independent module packages.

## 16. Rollout Plan

Phase 1: Local module runtime

- Add module database tables.
- Add `modules/` discovery.
- Parse and validate `module.json`.
- Register module routes, views, and assets.
- Import module menus.
- Scan module permission nodes.
- Enable/disable modules.
- Log lifecycle operations.

Phase 2: Module Center

- Add admin UI for installed modules.
- Add install, enable, disable, uninstall actions.
- Add install logs and error details.
- Add manifest permission preview.

Phase 3: Versioning and migration safety

- Track module versions.
- Track module migrations.
- Add upgrade flow.
- Add rollback support where migrations define rollback.
- Add compatibility checks.

Phase 4: Review and signature

- Add module source management.
- Add signature verification.
- Add production policy to reject unsigned third-party modules.
- Add automated review checklist tooling.

Phase 5: Module repository and long-term operations

- Add official/partner/community repository support.
- Add published module metadata.
- Add update notifications.
- Add suspended/deprecated module handling.
- Add future authorization or commercial licensing hooks.

## 17. Open Decisions

These should be decided before implementation:

1. Whether internal official modules must be signed in production.
   Recommended: yes.

2. Whether private modules can be installed from zip in production.
   Recommended: allow only when an explicit environment flag is enabled.

3. Whether module assets are served by a controller or published into `public`.
   Recommended: serve through a controlled route first; publishing can be added later for performance.

4. Whether module migrations may delete tables during uninstall.
   Recommended: never by default; require explicit destructive uninstall.

5. Whether third-party modules can register public frontend routes.
   Recommended: not in phase 1; start with admin modules only.

## 18. Non-Goals for the First Version

The first version should not include:

- public marketplace UI
- payments or commercial licensing
- automatic remote updates
- full PHP sandboxing
- cross-installation module telemetry
- automatic code review without human approval

These can be added after the runtime and governance model are stable.

## 19. Success Criteria

The module container is successful when:

- a team module can be placed under `modules/`, installed, enabled, and accessed from the admin menu without copying files into host app directories;
- existing `system` and `mall` modules continue to work unchanged;
- module menus and permission nodes are imported and filtered through the existing permission system;
- module install, enable, disable, upgrade, and uninstall operations are logged;
- production can reject unsigned third-party modules;
- reviewed third-party modules can be installed from a signed package;
- module data is preserved by default during uninstall.

