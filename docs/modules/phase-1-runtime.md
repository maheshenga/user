# Module Runtime Phase 1

Phase 1 provides a local runtime container for internal and manually reviewed modules. It keeps EasyAdmin8's legacy controllers, views, menus, nodes, and assets working while allowing enabled modules to register their own admin runtime surface.

## Module Location

Local modules live under `modules/{StudlyName}` and must include a `module.json` manifest.

Example:

```text
modules/
  Blog/
    module.json
    src/
      Controllers/
    resources/
      views/
    assets/
      js/
```

The manifest `admin_prefix` is the module's admin URL prefix. It must not collide with reserved EasyAdmin8 admin prefixes such as existing first-party controller directories.

## Commands

Run migrations before using the module runtime:

```bash
php artisan migrate
```

Discover local module manifests:

```bash
php artisan module:discover
```

Install and enable a module:

```bash
php artisan module:install blog
php artisan module:enable blog
```

List module state:

```bash
php artisan module:list
```

Disable a module:

```bash
php artisan module:disable blog
```

## Runtime Behavior

Enabled modules are resolved before legacy admin controllers only for non-reserved `admin_prefix` values. Reserved EasyAdmin8 admin prefixes continue to resolve through the legacy runtime.

Legacy EasyAdmin8 controllers, views, menus, nodes, and assets keep their existing paths. A disabled module does not handle admin routes, views, or assets.

Module views are registered as:

```text
modules.{admin_prefix}::{controller}.{action}
```

For example, a module with `admin_prefix` set to `blog` can render:

```text
modules.blog::post.index
```

Module assets are served after login from:

```text
/module-assets/{admin_prefix}/{path}
```

For example:

```text
/module-assets/blog/js/post.js
```

Disable and uninstall-preserve keep module-owned data in place. Phase 1 intentionally does not delete module tables or business data during these lifecycle operations.

Partner and community signature enforcement, package review workflow, marketplace distribution, and automated third-party trust checks are outside Phase 1. Third-party modules should still be manually reviewed before being placed under `modules/`.

## Verification

For this repository's Windows development setup, the SQLite-backed test runner is:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

The module command table should include `name`, `version`, `type`, `status`, and `admin_prefix` columns:

```bash
php artisan module:list
```
