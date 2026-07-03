# Module Container Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first local runtime for EasyAdmin8 modules so a reviewed team or private module can live under `modules/`, be discovered, installed, enabled, routed, rendered, granted menu and node records, disabled, and logged without copying files into host application directories.

**Architecture:** Keep the current EasyAdmin8 convention route compatible and add a manifest-driven module container beside it. The container owns module metadata, state, discovery, lifecycle actions, module-first route resolution, module Blade namespaces, controlled asset serving, menu import, and module controller node scanning.

**Tech Stack:** PHP 8.3, Laravel 13, Composer PSR-4 autoloading, PHPUnit 12, MySQL-compatible migrations, existing EasyAdmin8 `system_menu` and `system_node` tables.

## Global Constraints

- Existing admin URLs under `/admin/{secondary}/{controller}/{action}` must keep working.
- Existing controllers under `app/Http/Controllers/admin`, views under `resources/views/admin`, and JS under `public/static/admin/js` must keep working.
- Phase 1 is local runtime only: no Module Center UI, no marketplace, no package signing, no remote update service, no commercial licensing.
- Phase 1 supports admin modules only; public frontend module routes are excluded.
- Uninstall and disable operations must preserve module data by default.
- Third-party governance is represented in manifest fields and stored state, but signature enforcement is Phase 4.
- Module node strings must stay compatible with the current format, for example `mall/goods/index`.
- Local module root is `modules/` at the repository root.

---

## File Structure

- Modify `composer.json`: add `"Modules\\": "modules/"` PSR-4 autoloading.
- Modify `phpunit.xml`: run automated tests against SQLite in memory so local deployment data is never dropped by test commands.
- Modify `tests/Feature/ExampleTest.php`: align the existing smoke test with the real `/` behavior, which redirects to `/admin`.
- Create `config/modules.php`: local module container settings, allowed module types, and phase policy flags.
- Create `database/migrations/2026_07_03_000001_create_system_module_tables.php`: create `system_module`, `system_module_version`, `system_module_migration`, `system_module_log`, and `system_module_source`.
- Create `app/Models/SystemModule.php`: Eloquent model for `system_module`.
- Create `app/Models/SystemModuleLog.php`: Eloquent model for `system_module_log`.
- Create `app/Models/SystemModuleMigration.php`: Eloquent model for `system_module_migration`.
- Create `app/Models/SystemModuleVersion.php`: Eloquent model for `system_module_version`.
- Create `app/Models/SystemModuleSource.php`: Eloquent model for `system_module_source`.
- Create `app/Modules/ModuleManifest.php`: validate and normalize `module.json`.
- Create `app/Modules/ModuleManager.php`: discover module directories and expose enabled module metadata.
- Create `app/Modules/ModuleRepository.php`: read and write installed module database state.
- Create `app/Modules/ModuleInstaller.php`: install, enable, disable, uninstall, import menus, write lifecycle logs.
- Create `app/Modules/ModuleRouteResolver.php`: resolve admin module routes before legacy controllers.
- Create `app/Modules/ModuleViewRegistrar.php`: register Blade namespaces for enabled modules.
- Create `app/Modules/ModuleNodeScanner.php`: scan enabled module controllers and return EasyAdmin8 node rows.
- Create `app/Http/Controllers/common/ModuleAssetController.php`: serve enabled module assets from `modules/{Module}/assets`.
- Modify `app/Providers/AppServiceProvider.php`: register module bindings and boot module view namespaces.
- Modify `routes/web.php`: route module assets and resolve `/admin/{secondary}/{controller}/{action}` through `ModuleRouteResolver`.
- Modify `routes/console.php`: add Phase 1 Artisan commands `module:discover`, `module:install`, `module:enable`, `module:disable`, `module:list`.
- Modify `app/Http/Controllers/common/AdminController.php`: prefer module JS path and module views when the current `secondary` belongs to an enabled module.
- Modify `app/Http/Services/NodeService.php`: merge host controller nodes with enabled module controller nodes.
- Create `tests/Unit/Modules/ModuleManifestTest.php`: manifest validation unit tests.
- Create `tests/Unit/Modules/ModuleManagerTest.php`: discovery and enabled lookup tests.
- Create `tests/Concerns/CreatesModuleTestSchema.php`: minimal EasyAdmin host tables for module feature tests.
- Create `tests/Feature/Modules/ModuleLifecycleTest.php`: install, enable, disable, menu import, and log tests.
- Create `tests/Feature/Modules/ModuleRuntimeTest.php`: route, view, asset, and node scanning tests.
- Create `tests/Fixtures/modules/Blog/module.json`: fixture manifest.
- Create `tests/Fixtures/modules/Blog/src/Controllers/PostController.php`: fixture admin controller.
- Create `tests/Fixtures/modules/Blog/resources/views/post/index.blade.php`: fixture Blade view.
- Create `tests/Fixtures/modules/Blog/assets/js/post.js`: fixture asset.

### Shared Interfaces

```php
namespace App\Modules;

final readonly class ModuleManifest
{
    public static function fromFile(string $path): self;
    public function name(): string;
    public function title(): string;
    public function vendor(): string;
    public function version(): string;
    public function type(): string;
    public function namespace(): string;
    public function adminPrefix(): string;
    public function path(): string;
    public function controllersPath(): string;
    public function viewsPath(): string;
    public function assetsPath(): string;
    public function migrationsPath(): ?string;
    public function menus(): array;
    public function permissions(): array;
    public function toArray(): array;
}

final class ModuleManager
{
    /** @return array<string, ModuleManifest> */
    public function discover(): array;
    public function manifest(string $name): ?ModuleManifest;
    public function enabledByPrefix(string $adminPrefix): ?ModuleManifest;
    /** @return array<string, ModuleManifest> */
    public function enabled(): array;
}

final class ModuleRepository
{
    public function upsertDiscovered(ModuleManifest $manifest): void;
    public function installed(string $name): ?\App\Models\SystemModule;
    public function enabledByPrefix(string $adminPrefix): ?\App\Models\SystemModule;
    public function setStatus(string $name, string $status, ?string $error = null): \App\Models\SystemModule;
    public function log(string $action, string $name, ?string $oldState, ?string $newState, string $result, ?string $error = null, ?int $actorId = null): void;
}

final class ModuleRouteResolver
{
    public function resolve(string $secondary, string $controller, string $action): array;
}
```

---

### Task 1: Persistence, Configuration, and Autoloading

**Files:**
- Modify: `composer.json`
- Modify: `phpunit.xml`
- Create: `config/modules.php`
- Create: `database/migrations/2026_07_03_000001_create_system_module_tables.php`
- Create: `app/Models/SystemModule.php`
- Create: `app/Models/SystemModuleLog.php`
- Create: `app/Models/SystemModuleMigration.php`
- Create: `app/Models/SystemModuleVersion.php`
- Create: `app/Models/SystemModuleSource.php`
- Create: `tests/Concerns/CreatesModuleTestSchema.php`
- Test: `tests/Feature/Modules/ModuleLifecycleTest.php`
- Test: `tests/Feature/ExampleTest.php`

**Interfaces:**
- Consumes: existing Laravel migration, config, and Eloquent systems.
- Produces: `system_module*` tables and models used by all later module services.

- [ ] **Step 1: Isolate test database and fixture autoloading**

Modify `phpunit.xml` so PHPUnit never runs `migrate:fresh` against the deployed MySQL database:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Modify `composer.json` `autoload-dev.psr-4` so fixture module classes can autoload during tests:

```json
"autoload-dev": {
    "psr-4": {
        "Tests\\": "tests/",
        "Modules\\Blog\\": "tests/Fixtures/modules/Blog/src/"
    }
}
```

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar dump-autoload
```

Expected: Composer regenerates autoload files without errors.

- [ ] **Step 2: Create minimal EasyAdmin host test schema helper**

Create `tests/Concerns/CreatesModuleTestSchema.php`:

```php
<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesModuleTestSchema
{
    protected function createEasyAdminHostTables(): void
    {
        if (!Schema::hasTable('system_menu')) {
            Schema::create('system_menu', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pid')->default(0);
                $table->string('title', 120);
                $table->string('icon', 120)->nullable();
                $table->string('href', 255)->nullable();
                $table->string('target', 40)->default('_self');
                $table->integer('sort')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedBigInteger('create_time')->nullable();
                $table->unsignedBigInteger('update_time')->nullable();
                $table->unsignedBigInteger('delete_time')->nullable();
            });
        }

        if (!Schema::hasTable('system_node')) {
            Schema::create('system_node', function (Blueprint $table) {
                $table->id();
                $table->string('node', 255)->unique();
                $table->string('title', 120)->nullable();
                $table->unsignedTinyInteger('type')->default(2);
                $table->unsignedTinyInteger('is_auth')->default(1);
            });
        }

        if (!Schema::hasTable('system_admin')) {
            Schema::create('system_admin', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('status')->default(1);
                $table->string('auth_ids', 255)->nullable();
            });
        }

        if (!Schema::hasTable('system_auth')) {
            Schema::create('system_auth', function (Blueprint $table) {
                $table->id();
            });
        }

        if (!Schema::hasTable('system_auth_node')) {
            Schema::create('system_auth_node', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('auth_id');
                $table->unsignedBigInteger('node_id');
            });
        }

        DB::table('system_admin')->updateOrInsert(
            ['id' => 1],
            ['status' => 1, 'auth_ids' => '']
        );
    }
}
```

- [ ] **Step 3: Write the failing migration smoke test**

Add `tests/Feature/Modules/ModuleLifecycleTest.php`:

```php
<?php

namespace Tests\Feature\Modules;

use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleLifecycleTest extends TestCase
{
    use CreatesModuleTestSchema;

    public function test_module_tables_are_created_by_migrations(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->assertTrue(Schema::hasTable('system_module'));
        $this->assertTrue(Schema::hasTable('system_module_version'));
        $this->assertTrue(Schema::hasTable('system_module_migration'));
        $this->assertTrue(Schema::hasTable('system_module_log'));
        $this->assertTrue(Schema::hasTable('system_module_source'));
    }
}
```

- [ ] **Step 4: Run test to verify it fails before the migration exists**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit --filter ModuleLifecycleTest::test_module_tables_are_created_by_migrations
```

Expected: FAIL because `system_module` does not exist.

- [ ] **Step 5: Add Composer module autoloading**

Modify the `autoload.psr-4` block in `composer.json` to include:

```json
"psr-4": {
    "App\\": "app/",
    "Modules\\": "modules/",
    "Database\\Factories\\": "database/factories/",
    "Database\\Seeders\\": "database/seeders/"
}
```

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar dump-autoload
```

Expected: Composer regenerates autoload files without errors.

- [ ] **Step 6: Create module configuration**

Create `config/modules.php`:

```php
<?php

return [
    'path' => base_path('modules'),
    'asset_route_prefix' => 'module-assets',
    'allowed_types' => ['core', 'official', 'partner', 'community', 'private'],
    'local_unsigned_types' => ['core', 'official', 'private'],
    'production_requires_signature_for' => ['partner', 'community'],
    'cache_key' => 'easyadmin8.modules.enabled',
];
```

- [ ] **Step 7: Create module tables migration**

Create `database/migrations/2026_07_03_000001_create_system_module_tables.php` with `up()` creating:

```php
Schema::create('system_module', function (Blueprint $table) {
    $table->id();
    $table->string('name', 80)->unique();
    $table->string('title', 120);
    $table->string('vendor', 120);
    $table->string('version', 40);
    $table->string('type', 40);
    $table->string('trust_level', 40)->default('private');
    $table->string('status', 40)->default('discovered');
    $table->string('path', 500);
    $table->string('namespace', 180);
    $table->string('admin_prefix', 80)->unique();
    $table->string('signature_hash', 160)->nullable();
    $table->unsignedBigInteger('installed_at')->nullable();
    $table->unsignedBigInteger('enabled_at')->nullable();
    $table->unsignedBigInteger('disabled_at')->nullable();
    $table->text('last_error')->nullable();
    $table->json('config_json')->nullable();
    $table->unsignedBigInteger('create_time')->nullable();
    $table->unsignedBigInteger('update_time')->nullable();
    $table->unsignedBigInteger('delete_time')->nullable();
});

Schema::create('system_module_version', function (Blueprint $table) {
    $table->id();
    $table->string('module', 80);
    $table->string('version', 40);
    $table->json('manifest_json');
    $table->unsignedBigInteger('installed_at')->nullable();
    $table->unsignedBigInteger('create_time')->nullable();
    $table->index(['module', 'version']);
});

Schema::create('system_module_migration', function (Blueprint $table) {
    $table->id();
    $table->string('module', 80);
    $table->string('migration', 180);
    $table->unsignedInteger('batch')->default(1);
    $table->unsignedBigInteger('ran_at');
    $table->unique(['module', 'migration']);
});

Schema::create('system_module_log', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('admin_id')->nullable();
    $table->string('module', 80);
    $table->string('action', 40);
    $table->string('old_state', 40)->nullable();
    $table->string('new_state', 40)->nullable();
    $table->string('old_version', 40)->nullable();
    $table->string('new_version', 40)->nullable();
    $table->unsignedBigInteger('started_at');
    $table->unsignedBigInteger('finished_at')->nullable();
    $table->string('result', 40);
    $table->text('error_message')->nullable();
});

Schema::create('system_module_source', function (Blueprint $table) {
    $table->id();
    $table->string('name', 80)->unique();
    $table->string('title', 120);
    $table->string('type', 40)->default('private');
    $table->string('url', 500)->nullable();
    $table->unsignedTinyInteger('status')->default(1);
    $table->unsignedBigInteger('create_time')->nullable();
    $table->unsignedBigInteger('update_time')->nullable();
});
```

The migration `down()` must drop tables in reverse order.

- [ ] **Step 8: Create models**

Each model extends `App\Models\BaseModel` and defines the exact table:

```php
<?php

namespace App\Models;

class SystemModule extends BaseModel
{
    protected $table = 'system_module';
    protected $guarded = [];

    protected $casts = [
        'config_json' => 'array',
    ];
}
```

Create matching models with `$table` set to `system_module_log`, `system_module_migration`, `system_module_version`, and `system_module_source`. Use `protected $guarded = [];` in each. Add `manifest_json` cast to `SystemModuleVersion`.

- [ ] **Step 9: Run migration test**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit --filter ModuleLifecycleTest::test_module_tables_are_created_by_migrations
```

Expected: PASS.

- [ ] **Step 10: Fix the existing root smoke test**

Modify `tests/Feature/ExampleTest.php` so it asserts the existing route behavior:

```php
public function test_the_application_redirects_to_admin(): void
{
    $response = $this->get('/');

    $response->assertRedirect('/admin');
}
```

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Feature/ExampleTest.php
```

Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add composer.json phpunit.xml config/modules.php database/migrations/2026_07_03_000001_create_system_module_tables.php app/Models/SystemModule.php app/Models/SystemModuleLog.php app/Models/SystemModuleMigration.php app/Models/SystemModuleVersion.php app/Models/SystemModuleSource.php tests/Concerns/CreatesModuleTestSchema.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/ExampleTest.php
git commit -m "feat: add module persistence foundation"
```

---

### Task 2: Manifest Validation, Discovery, and Repository State

**Files:**
- Create: `app/Modules/ModuleManifest.php`
- Create: `app/Modules/ModuleManager.php`
- Create: `app/Modules/ModuleRepository.php`
- Test: `tests/Unit/Modules/ModuleManifestTest.php`
- Test: `tests/Unit/Modules/ModuleManagerTest.php`
- Create: `tests/Fixtures/modules/Blog/module.json`

**Interfaces:**
- Consumes: module config from Task 1 and `system_module` model.
- Produces: normalized `ModuleManifest` objects, module discovery, and enabled module lookup for route/view/node tasks.

- [ ] **Step 1: Create fixture manifest**

Create `tests/Fixtures/modules/Blog/module.json`:

```json
{
  "schema_version": "1.0",
  "name": "blog",
  "title": "Blog Module",
  "vendor": "easyadmin8",
  "version": "1.0.0",
  "type": "private",
  "core_version": "^8.0",
  "php": ">=8.3",
  "namespace": "Modules\\Blog",
  "entry": "src/Providers/BlogServiceProvider.php",
  "admin_prefix": "blog",
  "controllers": "src/Controllers",
  "views": "resources/views",
  "assets": "assets",
  "migrations": "database/migrations",
  "seeders": "database/seeders",
  "permissions": ["menu:write", "node:write"],
  "external_domains": [],
  "dependencies": {},
  "conflicts": {},
  "database": {
    "tables": ["blog_posts"],
    "preserve_on_uninstall": true
  },
  "menus": [
    {
      "title": "Blog",
      "icon": "fa fa-edit",
      "href": "",
      "children": [
        {
          "title": "Posts",
          "icon": "fa fa-file-text",
          "href": "blog/post/index"
        }
      ]
    }
  ]
}
```

- [ ] **Step 2: Write failing manifest tests**

Add `tests/Unit/Modules/ModuleManifestTest.php`:

```php
<?php

namespace Tests\Unit\Modules;

use App\Modules\ModuleManifest;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleManifestTest extends TestCase
{
    public function test_manifest_is_loaded_and_normalized(): void
    {
        $manifest = ModuleManifest::fromFile(base_path('tests/Fixtures/modules/Blog/module.json'));

        $this->assertSame('blog', $manifest->name());
        $this->assertSame('Blog Module', $manifest->title());
        $this->assertSame('Modules\\Blog', $manifest->namespace());
        $this->assertSame('blog', $manifest->adminPrefix());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/src/Controllers', $manifest->controllersPath());
        $this->assertSame('blog/post/index', $manifest->menus()[0]['children'][0]['href']);
    }

    public function test_manifest_rejects_missing_required_fields(): void
    {
        $path = base_path('storage/framework/testing-invalid-module.json');
        file_put_contents($path, json_encode(['name' => 'broken'], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('module.json missing required field: schema_version');

        ModuleManifest::fromFile($path);
    }
}
```

- [ ] **Step 3: Write failing discovery tests**

Add `tests/Unit/Modules/ModuleManagerTest.php`:

```php
<?php

namespace Tests\Unit\Modules;

use App\Models\SystemModule;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleManagerTest extends TestCase
{
    use CreatesModuleTestSchema;

    public function test_discovers_modules_from_configured_path(): void
    {
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        $modules = app(ModuleManager::class)->discover();

        $this->assertArrayHasKey('blog', $modules);
        $this->assertSame('blog', $modules['blog']->adminPrefix());
    }

    public function test_enabled_module_can_be_found_by_admin_prefix(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'enabled_at' => time(),
        ]);

        $manifest = app(ModuleManager::class)->enabledByPrefix('blog');

        $this->assertNotNull($manifest);
        $this->assertSame('blog', $manifest->name());
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Unit/Modules
```

Expected: FAIL because `App\Modules\ModuleManifest` does not exist.

- [ ] **Step 5: Implement `ModuleManifest`**

Create `app/Modules/ModuleManifest.php` with these behaviors:

```php
private const REQUIRED = [
    'schema_version',
    'name',
    'title',
    'vendor',
    'version',
    'type',
    'core_version',
    'namespace',
    'admin_prefix',
];
```

Implementation rules:
- `fromFile(string $path)` reads JSON with `JSON_THROW_ON_ERROR`.
- If a required field is absent or empty, throw `InvalidArgumentException("module.json missing required field: {$field}")`.
- `name`, `type`, and `admin_prefix` must match `/^[a-z][a-z0-9_]*$/`; invalid values throw `InvalidArgumentException("module.json invalid field: {$field}")`.
- Relative paths are resolved from `dirname($path)`.
- Default path fields: `controllers` is `src/Controllers`, `views` is `resources/views`, `assets` is `assets`.
- `menus()` and `permissions()` return arrays; absent values return `[]`.

- [ ] **Step 6: Implement `ModuleRepository`**

Create `app/Modules/ModuleRepository.php`:

```php
<?php

namespace App\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleLog;

final class ModuleRepository
{
    public function upsertDiscovered(ModuleManifest $manifest): void
    {
        SystemModule::query()->updateOrCreate(
            ['name' => $manifest->name()],
            [
                'title' => $manifest->title(),
                'vendor' => $manifest->vendor(),
                'version' => $manifest->version(),
                'type' => $manifest->type(),
                'trust_level' => $manifest->type(),
                'status' => SystemModule::query()->where('name', $manifest->name())->value('status') ?: 'discovered',
                'path' => $manifest->path(),
                'namespace' => $manifest->namespace(),
                'admin_prefix' => $manifest->adminPrefix(),
                'config_json' => $manifest->toArray(),
                'update_time' => time(),
            ]
        );
    }

    public function installed(string $name): ?SystemModule
    {
        return SystemModule::query()->where('name', $name)->first();
    }

    public function enabledByPrefix(string $adminPrefix): ?SystemModule
    {
        return SystemModule::query()
            ->where('admin_prefix', $adminPrefix)
            ->where('status', 'enabled')
            ->first();
    }

    public function setStatus(string $name, string $status, ?string $error = null): SystemModule
    {
        $module = SystemModule::query()->where('name', $name)->firstOrFail();
        $now = time();
        $payload = ['status' => $status, 'last_error' => $error, 'update_time' => $now];
        if ($status === 'enabled') {
            $payload['enabled_at'] = $now;
        }
        if ($status === 'disabled') {
            $payload['disabled_at'] = $now;
        }
        if ($status === 'installed' && empty($module->installed_at)) {
            $payload['installed_at'] = $now;
        }
        $module->update($payload);

        return $module->refresh();
    }

    public function log(string $action, string $name, ?string $oldState, ?string $newState, string $result, ?string $error = null, ?int $actorId = null): void
    {
        SystemModuleLog::query()->create([
            'admin_id' => $actorId,
            'module' => $name,
            'action' => $action,
            'old_state' => $oldState,
            'new_state' => $newState,
            'started_at' => time(),
            'finished_at' => time(),
            'result' => $result,
            'error_message' => $error,
        ]);
    }
}
```

- [ ] **Step 7: Implement `ModuleManager`**

Create `app/Modules/ModuleManager.php`:

```php
public function discover(): array
{
    $root = config('modules.path', base_path('modules'));
    if (!is_dir($root)) {
        return [];
    }

    $modules = [];
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'module.json';
        if (is_file($manifestPath)) {
            $manifest = ModuleManifest::fromFile($manifestPath);
            $modules[$manifest->name()] = $manifest;
        }
    }

    return $modules;
}
```

Also implement `manifest()`, `enabledByPrefix()`, and `enabled()` using `ModuleRepository`. `enabledByPrefix()` must return `null` when the database row is absent or not `enabled`.
`enabled()` and `enabledByPrefix()` must return empty results when `Schema::hasTable('system_module')` is false so `php artisan migrate` can run before module tables exist.

- [ ] **Step 8: Run unit tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Unit/Modules
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/ModuleManifest.php app/Modules/ModuleManager.php app/Modules/ModuleRepository.php tests/Unit/Modules/ModuleManifestTest.php tests/Unit/Modules/ModuleManagerTest.php tests/Fixtures/modules/Blog/module.json
git commit -m "feat: add module discovery services"
```

---

### Task 3: Runtime Routing, Views, Assets, and Admin Fetch Integration

**Files:**
- Create: `app/Modules/ModuleRouteResolver.php`
- Create: `app/Modules/ModuleViewRegistrar.php`
- Create: `app/Http/Controllers/common/ModuleAssetController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/common/AdminController.php`
- Create: `tests/Fixtures/modules/Blog/src/Controllers/PostController.php`
- Create: `tests/Fixtures/modules/Blog/resources/views/post/index.blade.php`
- Create: `tests/Fixtures/modules/Blog/assets/js/post.js`
- Test: `tests/Feature/Modules/ModuleRuntimeTest.php`

**Interfaces:**
- Consumes: enabled manifests from `ModuleManager`.
- Produces: module-first admin dispatch, Blade namespace registration, module asset serving, and module-aware `AdminController::fetch()`.

- [ ] **Step 1: Create fixture controller, view, and asset**

Create `tests/Fixtures/modules/Blog/src/Controllers/PostController.php`:

```php
<?php

namespace Modules\Blog\Controllers;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;

#[ControllerAnnotation(title: 'Posts', auth: true)]
class PostController extends AdminController
{
    #[NodeAnnotation(title: 'Post Index', auth: true)]
    public function index()
    {
        return $this->fetch();
    }
}
```

Create `tests/Fixtures/modules/Blog/resources/views/post/index.blade.php`:

```blade
module-blog-post-index
```

Create `tests/Fixtures/modules/Blog/assets/js/post.js`:

```javascript
define([], function () {
    return {
        index: function () {
            return 'module-blog-post';
        }
    };
});
```

- [ ] **Step 2: Write failing runtime tests**

Create `tests/Feature/Modules/ModuleRuntimeTest.php`:

```php
<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleRuntimeTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.path', base_path('tests/Fixtures/modules'));
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
            'enabled_at' => time(),
        ]);
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();
        $this->withSession(['admin.id' => 1]);
    }

    public function test_enabled_module_admin_route_renders_module_view(): void
    {
        $response = $this->get('/admin/blog/post/index');

        $response->assertOk();
        $response->assertSee('module-blog-post-index');
    }

    public function test_enabled_module_asset_is_served(): void
    {
        $response = $this->get('/module-assets/blog/js/post.js');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/javascript; charset=UTF-8');
        $response->assertSee('module-blog-post');
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Feature/Modules/ModuleRuntimeTest.php
```

Expected: FAIL because the route resolver and asset controller do not exist.

- [ ] **Step 4: Implement route resolver**

Create `app/Modules/ModuleRouteResolver.php`:

```php
<?php

namespace App\Modules;

use Illuminate\Support\Str;

final class ModuleRouteResolver
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function resolve(string $secondary, string $controller, string $action): array
    {
        $manifest = $this->modules->enabledByPrefix($secondary);
        if ($manifest !== null) {
            $class = $manifest->namespace() . '\\Controllers\\' . Str::studly($controller) . 'Controller';
            if (class_exists($class)) {
                return [$class, $action];
            }
        }

        $legacy = config('admin.controller_namespace') . $secondary . '\\' . Str::studly($controller) . 'Controller';

        return [$legacy, $action];
    }
}
```

- [ ] **Step 5: Add asset controller**

Create `app/Http/Controllers/common/ModuleAssetController.php`:

```php
<?php

namespace App\Http\Controllers\common;

use App\Modules\ModuleManager;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModuleAssetController extends Controller
{
    public function show(string $module, string $path, ModuleManager $manager): BinaryFileResponse|Response
    {
        $manifest = $manager->enabledByPrefix($module);
        abort_if($manifest === null, 404);
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);

        $file = $manifest->assetsPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        abort_if(!is_file($file), 404);

        return response()->file($file);
    }
}
```

- [ ] **Step 6: Register views and routes**

Create `app/Modules/ModuleViewRegistrar.php`:

```php
<?php

namespace App\Modules;

use Illuminate\Support\Facades\View;

final class ModuleViewRegistrar
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function registerEnabled(): void
    {
        foreach ($this->modules->enabled() as $manifest) {
            if (is_dir($manifest->viewsPath())) {
                View::addNamespace('modules.' . $manifest->adminPrefix(), $manifest->viewsPath());
            }
        }
    }
}
```

Modify `app/Providers/AppServiceProvider.php` boot method:

```php
public function boot(): void
{
    if (class_exists(\App\Modules\ModuleViewRegistrar::class)) {
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();
    }
}
```

Modify `routes/web.php`:

```php
Route::get('/module-assets/{module}/{path}', [\App\Http\Controllers\common\ModuleAssetController::class, 'show'])
    ->where('path', '.*')
    ->middleware([CheckInstall::class, CheckLogin::class]);
```

In the `/{secondary}/{controller}/{action}` closure, replace manual class construction with:

```php
[$className, $resolvedAction] = app(\App\Modules\ModuleRouteResolver::class)->resolve($secondary, $controller, $action);
return webRouteExtracted($className, $resolvedAction);
```

- [ ] **Step 7: Make `AdminController` module-aware**

In `initialize()`, after `$jsBasePath` is calculated, add:

```php
$moduleManifest = $secondary ? app(\App\Modules\ModuleManager::class)->enabledByPrefix($secondary) : null;
if ($moduleManifest) {
    $thisControllerJsPath = "module-assets/{$secondary}/js/" . strtolower($controller) . ".js";
    $autoloadJs = file_exists($moduleManifest->assetsPath() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . strtolower($controller) . '.js');
} else {
    $thisControllerJsPath = "admin/js/{$jsBasePath}.js";
    $autoloadJs = file_exists(public_path('static/' . $thisControllerJsPath));
}
```

In `fetch()`, before the legacy template name is assigned, add:

```php
if ($this->secondary && app(\App\Modules\ModuleManager::class)->enabledByPrefix($this->secondary)) {
    $moduleTemplate = 'modules.' . $this->secondary . '::' . $this->controller . '.' . $this->action;
    if (view()->exists($moduleTemplate)) {
        $template = $moduleTemplate;
    }
}
```

Keep the existing legacy `admin.{secondary}.{controller}.{action}` fallback.

- [ ] **Step 8: Run runtime tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Feature/Modules/ModuleRuntimeTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/ModuleRouteResolver.php app/Modules/ModuleViewRegistrar.php app/Http/Controllers/common/ModuleAssetController.php app/Providers/AppServiceProvider.php routes/web.php app/Http/Controllers/common/AdminController.php tests/Fixtures/modules/Blog/src/Controllers/PostController.php tests/Fixtures/modules/Blog/resources/views/post/index.blade.php tests/Fixtures/modules/Blog/assets/js/post.js tests/Feature/Modules/ModuleRuntimeTest.php
git commit -m "feat: add module runtime routing"
```

---

### Task 4: Lifecycle Installer, Menus, Nodes, and Commands

**Files:**
- Create: `app/Modules/ModuleInstaller.php`
- Create: `app/Modules/ModuleNodeScanner.php`
- Modify: `app/Http/Services/NodeService.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Modules/ModuleLifecycleTest.php`
- Test: `tests/Feature/Modules/ModuleRuntimeTest.php`

**Interfaces:**
- Consumes: `ModuleManager`, `ModuleRepository`, existing `system_menu`, `system_node`, and annotation scanning classes.
- Produces: install, enable, disable, uninstall-preserve behavior; menu rows; module node rows; lifecycle logs; CLI operations for Phase 1.

- [ ] **Step 1: Extend lifecycle tests**

Add to `tests/Feature/Modules/ModuleLifecycleTest.php`:

```php
public function test_module_can_be_installed_enabled_disabled_and_logged(): void
{
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->createEasyAdminHostTables();
    \Illuminate\Support\Facades\Config::set('modules.path', base_path('tests/Fixtures/modules'));

    $this->artisan('module:install', ['name' => 'blog'])->assertExitCode(0);
    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'installed']);
    $this->assertDatabaseHas('system_menu', ['href' => 'blog/post/index']);
    $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'install', 'result' => 'success']);

    $this->artisan('module:enable', ['name' => 'blog'])->assertExitCode(0);
    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'enabled']);
    $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'enable', 'result' => 'success']);

    $this->artisan('module:disable', ['name' => 'blog'])->assertExitCode(0);
    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'disabled']);
    $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'disable', 'result' => 'success']);
}
```

Add to `tests/Feature/Modules/ModuleRuntimeTest.php`:

```php
public function test_module_controller_nodes_are_scanned(): void
{
    $nodes = app(\App\Http\Services\NodeService::class)->getNodeList();
    $nodeNames = array_column($nodes, 'node');

    $this->assertContains('blog/post', $nodeNames);
    $this->assertContains('blog/post/index', $nodeNames);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Feature/Modules
```

Expected: FAIL because installer, commands, and scanner are missing.

- [ ] **Step 3: Implement `ModuleInstaller`**

Create `app/Modules/ModuleInstaller.php` with:

```php
public function install(string $name, ?int $actorId = null): void
{
    $manifest = $this->manager->manifest($name);
    if ($manifest === null) {
        throw new \InvalidArgumentException("Module not found: {$name}");
    }
    $current = $this->repository->installed($name);
    $oldState = $current?->status;

    $this->repository->upsertDiscovered($manifest);
    $this->importMenus($manifest);
    $this->repository->setStatus($name, 'installed');
    $this->repository->log('install', $name, $oldState, 'installed', 'success', null, $actorId);
    $this->clearCaches();
}
```

Add `enable()`, `disable()`, and `uninstallPreserve()`:
- `enable()` changes state to `enabled`, logs `enable`, clears caches.
- `disable()` changes state to `disabled`, logs `disable`, clears caches.
- `uninstallPreserve()` changes state to `uninstalled`, logs `uninstall`, clears caches, and leaves module database tables intact.

Implement `importMenus(ModuleManifest $manifest)`:
- Read `$manifest->menus()`.
- Insert parent menu when no existing row has the same `title`, `pid`, and empty `href`.
- Insert child menu when no existing row has the same `href`.
- Use fields: `pid`, `title`, `icon`, `href`, `target` = `_self`, `sort` = `0`, `status` = `1`, `create_time` = `time()`.
- Recurse through `children`.

Implement `clearCaches()`:

```php
Cache::forget(config('modules.cache_key'));
Cache::forget('version');
```

- [ ] **Step 4: Implement module node scanner**

Create `app/Modules/ModuleNodeScanner.php`:

```php
<?php

namespace App\Modules;

use App\Http\Services\auth\Node;

final class ModuleNodeScanner
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function getNodeList(): array
    {
        $nodes = [];
        foreach ($this->modules->enabled() as $manifest) {
            if (!is_dir($manifest->controllersPath())) {
                continue;
            }
            $moduleNodes = (new Node($manifest->controllersPath(), $manifest->namespace() . '\\Controllers'))->getNodeList();
            foreach ($moduleNodes as $node) {
                if (isset($node['node'])) {
                    $rawNode = preg_replace('#^Controllers/#', '', ltrim($node['node'], '/'));
                    $node['node'] = $manifest->adminPrefix() . '/' . $rawNode;
                }
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
```

Modify `app/Http/Services/NodeService.php` so `getNodeList()` merges host nodes and module nodes:

```php
$moduleNodes = class_exists(\App\Modules\ModuleNodeScanner::class)
    ? app(\App\Modules\ModuleNodeScanner::class)->getNodeList()
    : [];

return array_merge($nodeList, $moduleNodes);
```

- [ ] **Step 5: Register Artisan commands**

Modify `routes/console.php`:

```php
Artisan::command('module:discover', function () {
    foreach (app(\App\Modules\ModuleManager::class)->discover() as $manifest) {
        app(\App\Modules\ModuleRepository::class)->upsertDiscovered($manifest);
        $this->line($manifest->name() . ' ' . $manifest->version());
    }
})->purpose('Discover local EasyAdmin8 modules');

Artisan::command('module:install {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->install($name);
    $this->info("Installed module: {$name}");
})->purpose('Install a local EasyAdmin8 module');

Artisan::command('module:enable {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->enable($name);
    $this->info("Enabled module: {$name}");
})->purpose('Enable an installed EasyAdmin8 module');

Artisan::command('module:disable {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->disable($name);
    $this->info("Disabled module: {$name}");
})->purpose('Disable an EasyAdmin8 module');

Artisan::command('module:list', function () {
    $rows = \App\Models\SystemModule::query()
        ->orderBy('name')
        ->get(['name', 'version', 'type', 'status', 'admin_prefix'])
        ->map(fn ($module) => $module->toArray())
        ->all();
    $this->table(['name', 'version', 'type', 'status', 'admin_prefix'], $rows);
})->purpose('List EasyAdmin8 modules');
```

- [ ] **Step 6: Run feature tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit tests/Feature/Modules
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/ModuleInstaller.php app/Modules/ModuleNodeScanner.php app/Http/Services/NodeService.php routes/console.php tests/Feature/Modules/ModuleLifecycleTest.php tests/Feature/Modules/ModuleRuntimeTest.php
git commit -m "feat: add module lifecycle commands"
```

---

### Task 5: End-to-End Verification and Operator Notes

**Files:**
- Create: `docs/modules/phase-1-runtime.md`
- Modify: no runtime code unless verification exposes a defect.

**Interfaces:**
- Consumes: completed Phase 1 runtime.
- Produces: verified local deployment commands and usage notes for internal teams.

- [ ] **Step 1: Create operator notes**

Create `docs/modules/phase-1-runtime.md`:

````markdown
# Module Runtime Phase 1

Local modules live under `modules/{StudlyName}` and must include `module.json`.

Phase 1 commands:

```bash
php artisan migrate
php artisan module:discover
php artisan module:install blog
php artisan module:enable blog
php artisan module:list
php artisan module:disable blog
```

Runtime behavior:

- Enabled modules are resolved before legacy admin controllers only for their `admin_prefix`.
- Legacy EasyAdmin8 controllers, views, menus, nodes, and assets keep their existing paths.
- Module views are registered as `modules.{admin_prefix}::{controller}.{action}`.
- Module assets are served from `/module-assets/{admin_prefix}/{path}` after login.
- Disable and uninstall-preserve keep module-owned data in place.
- Partner and community signature enforcement is outside Phase 1.
````

- [ ] **Step 2: Run the full automated suite**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 3: Verify production routes still respond**

With the local server running, run:

```bash
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8000/admin | Select-Object StatusCode
```

Expected: `StatusCode` is `200`.

- [ ] **Step 4: Verify module command output**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe artisan module:list
```

Expected: output contains a table with `name`, `version`, `type`, `status`, and `admin_prefix` columns.

- [ ] **Step 5: Commit**

```bash
git add docs/modules/phase-1-runtime.md
git commit -m "docs: document module runtime phase 1"
```

---

## Self-Review Checklist

- Spec coverage: Phase 1 items are covered by Tasks 1 through 5: module tables, `modules/` discovery, manifest parsing, routes, views, assets, menu import, node scanning, enable/disable, and lifecycle logs.
- Compatibility: Task 3 preserves legacy route fallback and `AdminController::fetch()` legacy view fallback.
- Third-party long-term model: Manifest fields store `type`, `permissions`, `external_domains`, and database ownership; signing and review tooling remain outside Phase 1 by design.
- Data preservation: Task 4 defines `uninstallPreserve()` and does not delete module-owned tables.
- Test coverage: Unit tests cover manifest and discovery; feature tests cover lifecycle, route rendering, asset serving, and node scanning.
- Execution boundary: Each task ends with tests and a focused commit.
