# Module Center Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Phase 2 Module Center: backend module management, local and zip upgrades, version history, migration tracking, and rollback.

**Architecture:** Reuse Phase 1 module tables and services. Add small services for version history, migration running, filesystem backup/replacement, zip extraction, upgrade, and rollback. Add one existing-style admin controller and a few Blade pages; no new frontend stack.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, Blade/Layui, `ZipArchive`, PHPUnit through `composer run test:sqlite`.

---

## File Structure

- Create `app/Modules/ModuleVersionRecorder.php`
  - Records install/upgrade snapshots in `system_module_version`.
- Create `app/Modules/ModuleMigrationRunner.php`
  - Runs unrecorded module migrations and reversible rollback migrations.
- Create `app/Modules/ModuleFileStore.php`
  - Copies, replaces, backs up, deletes, and validates module directories.
- Create `app/Modules/ModuleZipExtractor.php`
  - Extracts zip packages safely into `storage/modules/tmp`.
- Create `app/Modules/ModuleUpgrader.php`
  - Coordinates local-directory and zip upgrade/install flows.
- Create `app/Modules/ModuleRollbacker.php`
  - Restores latest backup and metadata, with reversible migration checks.
- Modify `app/Modules/ModuleInstaller.php`
  - Record version snapshots on install.
- Modify `app/Modules/ModuleRepository.php`
  - Add small query/update helpers used by the UI and upgrade services.
- Create `app/Http/Controllers/admin/system/ModuleController.php`
  - Existing-style backend UI and AJAX actions.
- Create `resources/views/admin/system/module/index.blade.php`
- Create `resources/views/admin/system/module/detail.blade.php`
- Create `resources/views/admin/system/module/logs.blade.php`
- Create `resources/views/admin/system/module/upload.blade.php`
- Create `public/static/admin/js/system/module.js`
- Modify or add tests under `tests/Feature/Modules/`.
- Update `docs/modules/phase-1-runtime.md` or create `docs/modules/phase-2-module-center.md`.

---

### Task 1: Version History and Migration Runner

**Files:**
- Create: `app/Modules/ModuleVersionRecorder.php`
- Create: `app/Modules/ModuleMigrationRunner.php`
- Modify: `app/Modules/ModuleInstaller.php`
- Modify: `app/Modules/ModuleRepository.php`
- Test: `tests/Feature/Modules/ModulePhase2LifecycleTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Modules/ModulePhase2LifecycleTest.php` with these tests:

```php
<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleMigration;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModulePhase2LifecycleTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        parent::setUp();
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
    }

    public function test_install_records_module_version_snapshot(): void
    {
        Config::set('modules.path', base_path('tests/Fixtures/modules'));

        app(\App\Modules\ModuleInstaller::class)->install('blog');

        $this->assertDatabaseHas('system_module_version', [
            'module' => 'blog',
            'version' => '1.0.0',
        ]);
        $snapshot = SystemModuleVersion::query()->where('module', 'blog')->firstOrFail();
        $this->assertSame('blog', $snapshot->manifest_json['name']);
    }

    public function test_migration_runner_runs_only_unrecorded_module_migrations(): void
    {
        $root = storage_path('framework/testing-phase2-migrations');
        $this->deleteDirectory($root);
        mkdir($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'module.json', $this->manifest('migrator', 'migrator'));
        file_put_contents($root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_07_04_000001_create_migrator_table.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('migrator_items', fn (Blueprint $table) => $table->id()); }
    public function down(): void { Schema::dropIfExists('migrator_items'); }
};
PHP);
        $manifest = \App\Modules\ModuleManifest::fromFile($root.DIRECTORY_SEPARATOR.'module.json');

        try {
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);
            app(\App\Modules\ModuleMigrationRunner::class)->runPending($manifest);

            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('migrator_items'));
            $this->assertSame(1, SystemModuleMigration::query()->where('module', 'migrator')->count());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function manifest(string $name, string $prefix): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name),
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $prefix,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
        ], JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run tests to verify red**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php --filter "install_records_module_version|migration_runner_runs_only"
```

Expected: fail because `ModuleVersionRecorder` and `ModuleMigrationRunner` do not exist.

- [ ] **Step 3: Implement version recorder**

Create `app/Modules/ModuleVersionRecorder.php`:

```php
<?php

namespace App\Modules;

use App\Models\SystemModuleVersion;

final class ModuleVersionRecorder
{
    public function record(ModuleManifest $manifest, ?int $installedAt = null): void
    {
        SystemModuleVersion::query()->firstOrCreate(
            ['module' => $manifest->name(), 'version' => $manifest->version()],
            [
                'manifest_json' => $manifest->toArray(),
                'installed_at' => $installedAt ?? time(),
                'create_time' => time(),
            ]
        );
    }
}
```

- [ ] **Step 4: Implement migration runner**

Create `app/Modules/ModuleMigrationRunner.php`:

```php
<?php

namespace App\Modules;

use App\Models\SystemModuleMigration;
use RuntimeException;

final class ModuleMigrationRunner
{
    public function runPending(ModuleManifest $manifest): void
    {
        $path = $manifest->migrationsPath();
        if ($path === null || ! is_dir($path)) {
            return;
        }

        foreach ($this->migrationFiles($path) as $file) {
            $migration = basename($file);
            if (SystemModuleMigration::query()->where('module', $manifest->name())->where('migration', $migration)->exists()) {
                continue;
            }

            $instance = require $file;
            if (! is_object($instance) || ! method_exists($instance, 'up')) {
                throw new RuntimeException("Module migration [{$migration}] must return an object with up().");
            }

            $instance->up();
            SystemModuleMigration::query()->create([
                'module' => $manifest->name(),
                'migration' => $migration,
                'batch' => $this->nextBatch($manifest->name()),
                'ran_at' => time(),
            ]);
        }
    }

    public function assertReversible(ModuleManifest $manifest): void
    {
        foreach ($this->recordedFiles($manifest) as $file) {
            $instance = require $file;
            if (! is_object($instance) || ! method_exists($instance, 'down')) {
                throw new RuntimeException('Module rollback blocked by irreversible migration: '.basename($file));
            }
        }
    }

    public function rollbackRecorded(ModuleManifest $manifest): void
    {
        foreach (array_reverse($this->recordedFiles($manifest)) as $file) {
            $migration = basename($file);
            $instance = require $file;
            $instance->down();
            SystemModuleMigration::query()
                ->where('module', $manifest->name())
                ->where('migration', $migration)
                ->delete();
        }
    }

    private function nextBatch(string $module): int
    {
        return ((int) SystemModuleMigration::query()->where('module', $module)->max('batch')) + 1;
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(string $path): array
    {
        $files = glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);
        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function recordedFiles(ModuleManifest $manifest): array
    {
        $path = $manifest->migrationsPath();
        if ($path === null || ! is_dir($path)) {
            return [];
        }

        $files = [];
        foreach (SystemModuleMigration::query()->where('module', $manifest->name())->orderBy('id')->get() as $record) {
            $file = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$record->migration;
            if (is_file($file)) {
                $files[] = $file;
            }
        }
        return $files;
    }
}
```

- [ ] **Step 5: Wire install snapshots**

Modify `ModuleInstaller::__construct()` to inject `ModuleVersionRecorder`, then call it inside successful install operation after `upsertDiscovered($manifest)`:

```php
private readonly ModuleVersionRecorder $versions,
```

```php
$this->versions->record($manifest);
```

- [ ] **Step 6: Run tests green**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php --filter "install_records_module_version|migration_runner_runs_only"
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/ModuleVersionRecorder.php app/Modules/ModuleMigrationRunner.php app/Modules/ModuleInstaller.php tests/Feature/Modules/ModulePhase2LifecycleTest.php
git commit -m "feat: record module versions and migrations"
```

---

### Task 2: Module Files and Safe Zip Extraction

**Files:**
- Create: `app/Modules/ModuleFileStore.php`
- Create: `app/Modules/ModuleZipExtractor.php`
- Test: `tests/Feature/Modules/ModulePackageTest.php`

- [ ] **Step 1: Write failing package tests**

Create `tests/Feature/Modules/ModulePackageTest.php`:

```php
<?php

namespace Tests\Feature\Modules;

use Tests\TestCase;
use ZipArchive;

class ModulePackageTest extends TestCase
{
    public function test_zip_extractor_rejects_path_traversal_entries(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $zip = storage_path('framework/testing-module-bad.zip');
        @unlink($zip);
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('../escape.txt', 'bad');
        $archive->close();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('unsafe zip entry');
            app(\App\Modules\ModuleZipExtractor::class)->extract($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function test_file_store_backs_up_and_replaces_module_directory(): void
    {
        $root = storage_path('framework/testing-module-file-store');
        $current = $root.DIRECTORY_SEPARATOR.'current';
        $next = $root.DIRECTORY_SEPARATOR.'next';
        $this->deleteDirectory($root);
        mkdir($current, 0777, true);
        mkdir($next, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'old.txt', 'old');
        file_put_contents($next.DIRECTORY_SEPARATOR.'new.txt', 'new');

        try {
            $backup = app(\App\Modules\ModuleFileStore::class)->backup($current, 'blog', '1.0.0');
            app(\App\Modules\ModuleFileStore::class)->replace($current, $next);

            $this->assertFileExists($backup.DIRECTORY_SEPARATOR.'old.txt');
            $this->assertFileExists($current.DIRECTORY_SEPARATOR.'new.txt');
            $this->assertFileDoesNotExist($current.DIRECTORY_SEPARATOR.'old.txt');
        } finally {
            $this->deleteDirectory($root);
            $this->deleteDirectory(storage_path('modules/backups/blog'));
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run red**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModulePackageTest.php
```

Expected: fail because package services do not exist.

- [ ] **Step 3: Implement file store**

Create `app/Modules/ModuleFileStore.php`:

```php
<?php

namespace App\Modules;

use RuntimeException;

final class ModuleFileStore
{
    public function backup(string $source, string $module, string $version): string
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Module directory not found: {$source}");
        }

        $target = storage_path('modules/backups/'.$module.'/'.$version.'-'.date('YmdHis'));
        $this->copyDirectory($source, $target);
        return $target;
    }

    public function replace(string $target, string $source): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Replacement directory not found: {$source}");
        }

        $this->deleteDirectory($target);
        $this->copyDirectory($source, $target);
    }

    public function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) && ! is_link($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (! is_dir($target)) {
            mkdir($target, 0777, true);
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $from = $source.DIRECTORY_SEPARATOR.$entry;
            $to = $target.DIRECTORY_SEPARATOR.$entry;
            is_dir($from) && ! is_link($from) ? $this->copyDirectory($from, $to) : copy($from, $to);
        }
    }
}
```

- [ ] **Step 4: Implement zip extractor**

Create `app/Modules/ModuleZipExtractor.php`:

```php
<?php

namespace App\Modules;

use RuntimeException;
use ZipArchive;

final class ModuleZipExtractor
{
    public function extract(string $zipPath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required.');
        }

        $target = storage_path('modules/tmp/'.uniqid('module_', true));
        mkdir($target, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open module zip.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', $zip->getNameIndex($i));
                if (str_starts_with($name, '/') || str_contains($name, '../') || $name === '..') {
                    throw new RuntimeException("unsafe zip entry: {$name}");
                }
            }

            if (! $zip->extractTo($target)) {
                throw new RuntimeException('Unable to extract module zip.');
            }
        } finally {
            $zip->close();
        }

        return $this->moduleRoot($target);
    }

    private function moduleRoot(string $target): string
    {
        if (is_file($target.DIRECTORY_SEPARATOR.'module.json')) {
            return $target;
        }

        $children = array_values(array_filter(scandir($target) ?: [], fn ($entry) => $entry !== '.' && $entry !== '..'));
        if (count($children) === 1 && is_dir($target.DIRECTORY_SEPARATOR.$children[0]) && is_file($target.DIRECTORY_SEPARATOR.$children[0].DIRECTORY_SEPARATOR.'module.json')) {
            return $target.DIRECTORY_SEPARATOR.$children[0];
        }

        throw new RuntimeException('module.json not found in module zip.');
    }
}
```

- [ ] **Step 5: Run tests green**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModulePackageTest.php
```

Expected: pass or skip only ZipArchive-specific test if extension is absent.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/ModuleFileStore.php app/Modules/ModuleZipExtractor.php tests/Feature/Modules/ModulePackageTest.php
git commit -m "feat: add module package file handling"
```

---

### Task 3: Local and Zip Upgrade Service

**Files:**
- Create: `app/Modules/ModuleUpgrader.php`
- Modify: `app/Modules/ModuleRepository.php`
- Test: `tests/Feature/Modules/ModuleUpgradeTest.php`

- [ ] **Step 1: Write failing upgrade tests**

Create `tests/Feature/Modules/ModuleUpgradeTest.php` with:

```php
<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleVersion;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleUpgradeTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        parent::setUp();
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
    }

    public function test_local_upgrade_rejects_same_or_lower_version(): void
    {
        $root = storage_path('framework/testing-upgrade-same');
        $this->writeModule($root, 'blog', 'blog', '1.0.0');
        Config::set('modules.path', dirname($root));
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $root,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents($root.DIRECTORY_SEPARATOR.'module.json'), true),
        ]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must be greater');
            app(\App\Modules\ModuleUpgrader::class)->upgradeLocal('blog');
        } finally {
            $this->deleteDirectory(dirname($root));
        }
    }

    public function test_local_upgrade_updates_version_and_records_history(): void
    {
        $root = storage_path('framework/testing-upgrade-local/Blog');
        $this->writeModule($root, 'blog', 'blog', '1.0.0');
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $root,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents($root.DIRECTORY_SEPARATOR.'module.json'), true),
        ]);
        $this->writeModule($root, 'blog', 'blog', '1.1.0');

        try {
            app(\App\Modules\ModuleUpgrader::class)->upgradeLocal('blog');

            $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.1.0']);
            $this->assertDatabaseHas('system_module_version', ['module' => 'blog', 'version' => '1.1.0']);
            $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'upgrade', 'result' => 'success']);
        } finally {
            $this->deleteDirectory(storage_path('framework/testing-upgrade-local'));
            $this->deleteDirectory(storage_path('modules/backups/blog'));
        }
    }

    private function writeModule(string $root, string $name, string $prefix, string $version): void
    {
        if (! is_dir($root)) mkdir($root, 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'module.json', json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name),
            'vendor' => 'easyadmin8',
            'version' => $version,
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $prefix,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
        ], JSON_THROW_ON_ERROR));
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run red**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleUpgradeTest.php
```

Expected: fail because `ModuleUpgrader` does not exist.

- [ ] **Step 3: Add repository update helper**

Add to `ModuleRepository`:

```php
public function updateFromManifest(ModuleManifest $manifest, string $status): void
{
    SystemModule::query()->where('name', $manifest->name())->update([
        'title' => $manifest->title(),
        'vendor' => $manifest->vendor(),
        'version' => $manifest->version(),
        'type' => $manifest->type(),
        'trust_level' => $manifest->type(),
        'status' => $status,
        'path' => $manifest->path(),
        'namespace' => $manifest->namespace(),
        'admin_prefix' => $manifest->adminPrefix(),
        'config_json' => $manifest->toArray(),
        'last_error' => null,
        'update_time' => time(),
    ]);
}
```

- [ ] **Step 4: Implement upgrader**

Create `app/Modules/ModuleUpgrader.php`:

```php
<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ModuleUpgrader
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleVersionRecorder $versions,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleFileStore $files,
        private readonly ModuleZipExtractor $zip,
        private readonly ReservedAdminPrefixRegistry $reservedPrefixes,
        private readonly ModuleInstaller $installer,
    ) {}

    public function upgradeLocal(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $manifest = ModuleManifest::fromFile($module->path.DIRECTORY_SEPARATOR.'module.json');
        $this->upgradeInstalled($manifest, (string) $module->status, $actorId);
    }

    public function upgradeZip(string $zipPath, ?string $expectedName = null, ?int $actorId = null): void
    {
        $moduleRoot = $this->zip->extract($zipPath);
        $manifest = ModuleManifest::fromFile($moduleRoot.DIRECTORY_SEPARATOR.'module.json');
        if ($expectedName !== null && $manifest->name() !== $expectedName) {
            throw new InvalidArgumentException('Uploaded module name does not match target module.');
        }

        $installed = $this->repository->installed($manifest->name());
        if ($installed === null) {
            $target = base_path('modules/'.str_replace(' ', '', ucwords(str_replace('_', ' ', $manifest->name()))));
            $this->files->replace($target, $moduleRoot);
            $this->installer->install($manifest->name(), $actorId);
            return;
        }

        $this->files->backup((string) $installed->path, $manifest->name(), (string) $installed->version);
        $this->files->replace((string) $installed->path, $moduleRoot);
        $manifest = ModuleManifest::fromFile($installed->path.DIRECTORY_SEPARATOR.'module.json');
        $this->upgradeInstalled($manifest, (string) $installed->status, $actorId);
    }

    private function upgradeInstalled(ModuleManifest $manifest, string $status, ?int $actorId): void
    {
        $current = $this->repository->installed($manifest->name());
        if ($current === null) {
            throw new InvalidArgumentException("Module not installed: {$manifest->name()}");
        }
        if (! in_array($current->status, ['installed', 'enabled', 'disabled'], true)) {
            throw new InvalidArgumentException("Module [{$manifest->name()}] cannot be upgraded from status [{$current->status}]");
        }
        if (version_compare($manifest->version(), (string) $current->version, '<=')) {
            throw new InvalidArgumentException('Upgrade version must be greater than installed version.');
        }

        $oldState = (string) $current->status;
        try {
            DB::transaction(function () use ($manifest, $status): void {
                $this->reservedPrefixes->assertAllowed($manifest->adminPrefix(), $manifest->name());
                $this->migrations->runPending($manifest);
                $this->repository->updateFromManifest($manifest, $status);
                $this->versions->record($manifest);
                $this->repository->log('upgrade', $manifest->name(), $status, $status, 'success');
            });
        } catch (Throwable $exception) {
            $this->repository->setLastError($manifest->name(), $exception->getMessage());
            $this->repository->log('upgrade', $manifest->name(), $oldState, $oldState, 'failed', $exception->getMessage(), $actorId);
            throw $exception;
        }

        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }
}
```

Ponytail note: do not add a separate state machine. Existing `status` strings are enough.

- [ ] **Step 5: Run tests green**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleUpgradeTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/ModuleUpgrader.php app/Modules/ModuleRepository.php tests/Feature/Modules/ModuleUpgradeTest.php
git commit -m "feat: add module upgrade service"
```

---

### Task 4: Rollback Service

**Files:**
- Create: `app/Modules/ModuleRollbacker.php`
- Modify: `app/Modules/ModuleRepository.php`
- Test: `tests/Feature/Modules/ModuleRollbackTest.php`

- [ ] **Step 1: Write failing rollback tests**

Create `tests/Feature/Modules/ModuleRollbackTest.php`:

```php
<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleVersion;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleRollbackTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
    }

    public function test_rollback_restores_latest_backup_and_previous_version_metadata(): void
    {
        $root = storage_path('framework/testing-rollback/Blog');
        $backup = storage_path('modules/backups/blog/1.0.0-20260704000000');
        $this->deleteDirectory(storage_path('framework/testing-rollback'));
        $this->deleteDirectory(storage_path('modules/backups/blog'));
        mkdir($root, 0777, true);
        mkdir($backup, 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', 'blog', '1.1.0'));
        file_put_contents($root.DIRECTORY_SEPARATOR.'new.txt', 'new');
        file_put_contents($backup.DIRECTORY_SEPARATOR.'module.json', $this->manifest('blog', 'blog', '1.0.0'));
        file_put_contents($backup.DIRECTORY_SEPARATOR.'old.txt', 'old');
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'easyadmin8',
            'version' => '1.1.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $root,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents($root.DIRECTORY_SEPARATOR.'module.json'), true),
        ]);
        SystemModuleVersion::query()->create([
            'module' => 'blog',
            'version' => '1.0.0',
            'manifest_json' => json_decode(file_get_contents($backup.DIRECTORY_SEPARATOR.'module.json'), true),
            'installed_at' => time(),
            'create_time' => time(),
        ]);

        try {
            app(\App\Modules\ModuleRollbacker::class)->rollback('blog');

            $this->assertDatabaseHas('system_module', ['name' => 'blog', 'version' => '1.0.0']);
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.'old.txt');
            $this->assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'new.txt');
            $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'rollback', 'result' => 'success']);
        } finally {
            $this->deleteDirectory(storage_path('framework/testing-rollback'));
            $this->deleteDirectory(storage_path('modules/backups/blog'));
        }
    }

    private function manifest(string $name, string $prefix, string $version): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name),
            'vendor' => 'easyadmin8',
            'version' => $version,
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $prefix,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
        ], JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
```

- [ ] **Step 2: Run red**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleRollbackTest.php
```

Expected: fail because `ModuleRollbacker` does not exist.

- [ ] **Step 3: Add repository restore helper**

Add to `ModuleRepository`:

```php
public function restoreVersion(ModuleManifest $manifest, string $status): void
{
    $this->updateFromManifest($manifest, $status);
}
```

- [ ] **Step 4: Implement rollbacker**

Create `app/Modules/ModuleRollbacker.php`:

```php
<?php

namespace App\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ModuleRollbacker
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleFileStore $files,
    ) {}

    public function rollback(string $name, ?int $actorId = null): void
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("Module not installed: {$name}");
        }

        $backup = $this->latestBackup($name);
        $manifest = ModuleManifest::fromFile($backup.DIRECTORY_SEPARATOR.'module.json');
        $currentManifest = ModuleManifest::fromFile($module->path.DIRECTORY_SEPARATOR.'module.json');

        try {
            DB::transaction(function () use ($module, $manifest, $currentManifest, $backup): void {
                $this->migrations->assertReversible($currentManifest);
                $this->migrations->rollbackRecorded($currentManifest);
                $this->files->replace((string) $module->path, $backup);
                $this->repository->restoreVersion($manifest, (string) $module->status);
                $this->repository->log('rollback', $manifest->name(), (string) $module->status, (string) $module->status, 'success');
            });
        } catch (Throwable $exception) {
            $this->repository->setLastError($name, $exception->getMessage());
            $this->repository->log('rollback', $name, (string) $module->status, (string) $module->status, 'failed', $exception->getMessage(), $actorId);
            throw $exception;
        }

        Cache::forget(config('modules.cache_key'));
        Cache::forget('version');
    }

    private function latestBackup(string $name): string
    {
        $root = storage_path('modules/backups/'.$name);
        $backups = array_values(array_filter(glob($root.DIRECTORY_SEPARATOR.'*') ?: [], 'is_dir'));
        rsort($backups);
        if ($backups === []) {
            throw new RuntimeException("No backup found for module [{$name}].");
        }
        return $backups[0];
    }
}
```

- [ ] **Step 5: Run tests green**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleRollbackTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/ModuleRollbacker.php app/Modules/ModuleRepository.php tests/Feature/Modules/ModuleRollbackTest.php
git commit -m "feat: add module rollback service"
```

---

### Task 5: Backend Module Center UI

**Files:**
- Create: `app/Http/Controllers/admin/system/ModuleController.php`
- Create: `resources/views/admin/system/module/index.blade.php`
- Create: `resources/views/admin/system/module/detail.blade.php`
- Create: `resources/views/admin/system/module/logs.blade.php`
- Create: `resources/views/admin/system/module/upload.blade.php`
- Create: `public/static/admin/js/system/module.js`
- Test: `tests/Feature/Modules/ModuleCenterControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Create `tests/Feature/Modules/ModuleCenterControllerTest.php`:

```php
<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\CheckLogin;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\SystemModule;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleCenterControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->withoutMiddleware([CheckInstall::class, RateLimiting::class, CheckLogin::class, SystemLog::class, CheckAuth::class]);
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
    }

    public function test_module_center_index_returns_ajax_table_rows(): void
    {
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
        ]);

        $response = $this->getJson('/admin/system/module/index');

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.0.name', 'blog');
    }

    public function test_module_center_detail_renders_module_metadata(): void
    {
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => json_decode(file_get_contents(base_path('tests/Fixtures/modules/Blog/module.json')), true),
        ]);

        $response = $this->get('/admin/system/module/detail?name=blog');

        $response->assertOk();
        $response->assertSee('blog');
        $response->assertSee('1.0.0');
    }
}
```

- [ ] **Step 2: Run red**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleCenterControllerTest.php
```

Expected: fail because controller/views do not exist.

- [ ] **Step 3: Implement controller**

Create `app/Http/Controllers/admin/system/ModuleController.php`:

```php
<?php

namespace App\Http\Controllers\admin\system;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\SystemModule;
use App\Models\SystemModuleLog;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRollbacker;
use App\Modules\ModuleUpgrader;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'Module Center')]
class ModuleController extends AdminController
{
    public function initialize()
    {
        parent::initialize();
        $this->model = new SystemModule();
    }

    #[NodeAnnotation(title: 'List', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->wantsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where] = $this->buildTableParams();
        $query = $this->model->where($where);

        return json([
            'code' => 0,
            'msg' => '',
            'count' => $query->count(),
            'data' => $query->orderBy('name')->paginate($limit)->items(),
        ]);
    }

    #[NodeAnnotation(title: 'Detail', auth: true)]
    public function detail(): View
    {
        $name = (string) request('name');
        $module = SystemModule::query()->where('name', $name)->firstOrFail();
        $manifest = $module->config_json ?: [];
        $this->assign(compact('module', 'manifest'));
        return $this->fetch();
    }

    #[NodeAnnotation(title: 'Logs', auth: true)]
    public function logs(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->wantsJson()) {
            return $this->fetch();
        }
        $name = (string) request('name');
        $query = SystemModuleLog::query()->when($name !== '', fn ($q) => $q->where('module', $name));
        return json(['code' => 0, 'msg' => '', 'count' => $query->count(), 'data' => $query->orderByDesc('id')->paginate((int) request('limit', 20))->items()]);
    }

    #[NodeAnnotation(title: 'Upload Upgrade', auth: true)]
    public function upload(): View
    {
        return $this->fetch();
    }

    #[NodeAnnotation(title: 'Discover', auth: true)]
    public function discover(ModuleManager $manager, \App\Modules\ModuleRepository $repository): JsonResponse
    {
        foreach ($manager->discover() as $manifest) {
            $repository->upsertDiscovered($manifest);
        }
        return $this->success('Discovery complete');
    }

    #[NodeAnnotation(title: 'Install', auth: true)]
    public function install(ModuleInstaller $installer): JsonResponse
    {
        $installer->install((string) request('name'), session('admin.id'));
        return $this->success('Install complete');
    }

    #[NodeAnnotation(title: 'Enable', auth: true)]
    public function enable(ModuleInstaller $installer): JsonResponse
    {
        $installer->enable((string) request('name'), session('admin.id'));
        return $this->success('Enable complete');
    }

    #[NodeAnnotation(title: 'Disable', auth: true)]
    public function disable(ModuleInstaller $installer): JsonResponse
    {
        $installer->disable((string) request('name'), session('admin.id'));
        return $this->success('Disable complete');
    }

    #[NodeAnnotation(title: 'Uninstall Preserve', auth: true)]
    public function uninstall(ModuleInstaller $installer): JsonResponse
    {
        $installer->uninstallPreserve((string) request('name'), session('admin.id'));
        return $this->success('Uninstall complete');
    }

    #[NodeAnnotation(title: 'Local Upgrade', auth: true)]
    public function upgradeLocal(ModuleUpgrader $upgrader): JsonResponse
    {
        $upgrader->upgradeLocal((string) request('name'), session('admin.id'));
        return $this->success('Upgrade complete');
    }

    #[NodeAnnotation(title: 'Zip Upgrade', auth: true)]
    public function upgradeZip(ModuleUpgrader $upgrader): JsonResponse
    {
        $file = request()->file('file');
        if ($file === null) {
            return $this->error('Select a module zip file');
        }
        $upgrader->upgradeZip($file->getRealPath(), request('name'), session('admin.id'));
        return $this->success('Upgrade complete');
    }

    #[NodeAnnotation(title: 'Rollback', auth: true)]
    public function rollback(ModuleRollbacker $rollbacker): JsonResponse
    {
        $rollbacker->rollback((string) request('name'), session('admin.id'));
        return $this->success('Rollback complete');
    }
}
```

- [ ] **Step 4: Add minimal Blade views**

Create `resources/views/admin/system/module/index.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide" lay-filter="currentTable"></table>
    </div>
</div>
@include('admin.layout.foot')
```

Create `resources/views/admin/system/module/detail.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <pre>{{ json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>
@include('admin.layout.foot')
```

Create `resources/views/admin/system/module/logs.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide" lay-filter="currentTable"></table>
    </div>
</div>
@include('admin.layout.foot')
```

Create `resources/views/admin/system/module/upload.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <button type="button" class="layui-btn" id="moduleZipUpload">Upload module zip</button>
    </div>
</div>
@include('admin.layout.foot')
```

- [ ] **Step 5: Add minimal JS**

Create `public/static/admin/js/system/module.js`:

```js
layui.use(['table'], function () {
    var table = layui.table;
    table.render({
        elem: '#currentTable',
        url: '/admin/system/module/index',
        cols: [[
            {field: 'name', title: 'Name'},
            {field: 'version', title: 'Version'},
            {field: 'type', title: 'Type'},
            {field: 'status', title: 'Status'},
            {field: 'admin_prefix', title: 'Admin Prefix'}
        ]]
    });
});
```

- [ ] **Step 6: Run tests green**

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleCenterControllerTest.php
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/admin/system/ModuleController.php resources/views/admin/system/module public/static/admin/js/system/module.js tests/Feature/Modules/ModuleCenterControllerTest.php
git commit -m "feat: add module center admin UI"
```

---

### Task 6: Documentation and End-to-End Verification

**Files:**
- Create: `docs/modules/phase-2-module-center.md`
- Modify: no runtime code unless verification exposes a defect.

- [ ] **Step 1: Write operator docs**

Create `docs/modules/phase-2-module-center.md`:

```markdown
# Module Center Phase 2

Phase 2 adds the backend Module Center for local modules.

Supported:

- list installed and discovered modules;
- inspect manifest details and logs;
- install, enable, disable, and uninstall-preserve;
- upgrade from the local `modules/{Module}` directory;
- upload a zip and install/upgrade from it;
- roll back to the latest code backup;
- run reversible module migration rollback only when `down()` exists.

Not supported in Phase 2:

- third-party review;
- signatures;
- marketplace or remote repository;
- automatic updates;
- destructive uninstall.

Backups are stored under:

```text
storage/modules/backups/{module}/{version}-{timestamp}
```

Use this test command in the Windows development environment:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```
```

- [ ] **Step 2: Run full automated suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: all tests pass.

- [ ] **Step 3: Verify admin route**

With local server running:

```powershell
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8000/admin/system/module/index | Select-Object StatusCode
```

Expected: `StatusCode` is `200`, or redirected/login-protected according to local auth middleware. If auth blocks direct access, verify controller test coverage instead and record that limitation.

- [ ] **Step 4: Commit docs**

```bash
git add docs/modules/phase-2-module-center.md
git commit -m "docs: document module center phase 2"
```

---

## Self-Review Checklist

- Spec coverage:
  - Backend Module Center: Task 5.
  - Local upgrades: Task 3.
  - Zip upload upgrades: Tasks 2 and 3.
  - Version history: Task 1.
  - Migration tracking: Task 1.
  - Rollback: Task 4.
  - Docs and full verification: Task 6.
- Out of scope:
  - No third-party review.
  - No signature enforcement.
  - No marketplace.
  - No remote repository.
  - No auto-update scheduler.
- YAGNI:
  - No new frontend stack.
  - No new dependencies.
  - No workflow engine.
  - No backup retention policy.
- Test command:
  - Use `E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite`.
