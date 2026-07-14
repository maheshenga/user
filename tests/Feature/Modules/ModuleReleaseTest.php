<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use App\Modules\ModuleArtifactHasher;
use App\Modules\ModuleArtifactStore;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleManifest;
use App\Modules\ModuleManifestPolicy;
use App\Modules\ModuleMenuSynchronizer;
use App\Modules\ModuleMigrationRunner;
use App\Modules\ModuleReleaseManager;
use App\Modules\ModuleRepository;
use App\Modules\ModuleReviewService;
use App\Modules\ModuleRollbacker;
use App\Modules\ModuleUpgrader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleReleaseTest extends TestCase
{
    use CreatesModuleTestSchema;

    private string $root;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));

        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();

        $this->root = storage_path('framework/testing-module-releases');
        $this->deletePath($this->root);
        $this->deletePath(storage_path('modules/releases'));
        mkdir($this->root, 0777, true);
        Config::set('modules.path', $this->root);
        Config::set('modules.host_version', '8.0.0');
        Config::set('modules.allowed_permissions', ['menu:write', 'node:write', 'api:user']);
        Config::set('modules.signing_key', 'test-module-signing-key');
    }

    protected function tearDown(): void
    {
        $this->deletePath($this->root);
        $this->deletePath(storage_path('modules/releases'));

        parent::tearDown();
    }

    public function test_release_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasTable('system_module_release'));
        $this->assertTrue(Schema::hasTable('system_module_menu'));
        $this->assertTrue(Schema::hasColumns('system_module', [
            'active_release_id',
            'pending_release_id',
        ]));
        $this->assertSame('system_module_release', (new SystemModuleRelease)->getTable());
    }

    public function test_staged_release_is_immutable_from_later_source_changes(): void
    {
        $source = $this->writeModule('Blog', $this->manifest());
        file_put_contents($source.DIRECTORY_SEPARATOR.'src.txt', 'reviewed');
        $manifest = ModuleManifest::fromFile($source.DIRECTORY_SEPARATOR.'module.json');
        $hash = app(ModuleArtifactHasher::class)->hashDirectory($source);

        $releasePath = app(ModuleArtifactStore::class)->stage($manifest, $hash);
        file_put_contents($source.DIRECTORY_SEPARATOR.'src.txt', 'changed-after-review');

        $this->assertSame('reviewed', file_get_contents($releasePath.DIRECTORY_SEPARATOR.'src.txt'));
        $this->assertSame($hash, app(ModuleArtifactHasher::class)->hashDirectory($releasePath));
    }

    public function test_manifest_policy_rejects_unapproved_type_and_capability(): void
    {
        $path = $this->writeModule('BadType', $this->manifest(type: 'untrusted'));

        try {
            app(ModuleManifestPolicy::class)->validate(ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json'));
            $this->fail('Expected unsupported module type to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('模块类型', $exception->getMessage());
        }

        $path = $this->writeModule('BadAbility', $this->manifest(apiAbilities: ['root:all']));

        try {
            app(ModuleManifestPolicy::class)->validate(ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json'));
            $this->fail('Expected unsupported API capability to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('API 能力', $exception->getMessage());
        }

        $path = $this->writeModule('BadQuota', $this->manifest(apiQuotas: ['content.parse' => 0]));
        try {
            app(ModuleManifestPolicy::class)->validate(ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json'));
            $this->fail('Expected invalid API quota to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('API 配额', $exception->getMessage());
        }
    }

    public function test_zip_release_is_staged_without_mutating_enabled_module(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $activePath = $this->writeModule('Blog', $this->manifest());
        file_put_contents($activePath.DIRECTORY_SEPARATOR.'active.txt', 'version-1.0.0');
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $activePath,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => $this->manifest(),
        ]);

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-1.1.0.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => json_encode($this->manifest(version: '1.1.0'), JSON_THROW_ON_ERROR),
            'Blog/active.txt' => 'version-1.1.0',
        ]);

        $release = app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $this->assertSame('1.0.0', $module->version);
        $this->assertSame('enabled', $module->status);
        $this->assertSame('version-1.0.0', file_get_contents($activePath.DIRECTORY_SEPARATOR.'active.txt'));
        $this->assertSame($release->id, $module->pending_release_id);
        $this->assertSame('pending_review', $release->status);
        $this->assertDirectoryExists($release->artifact_path);
    }

    public function test_reuploading_same_pending_artifact_keeps_it_reviewable(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-pending.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => json_encode($this->manifest(), JSON_THROW_ON_ERROR),
        ]);

        $first = app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);
        $second = app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending_review', $second->status);
        $this->assertSame($second->id, SystemModule::query()->where('name', 'blog')->value('pending_release_id'));
    }

    public function test_artifact_hasher_rejects_directory_symlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Creating directory symlinks requires elevated Windows privileges.');
        }

        $source = $this->writeModule('Blog', $this->manifest());
        $outside = $this->root.DIRECTORY_SEPARATOR.'Outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'secret.txt', 'outside');
        symlink($outside, $source.DIRECTORY_SEPARATOR.'linked-directory');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('符号链接');
        app(ModuleArtifactHasher::class)->hashDirectory($source);
    }

    public function test_approved_release_activation_switches_pointer_and_preserves_enabled_state(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $activePath = $this->writeModule('Blog', $this->manifest());
        file_put_contents($activePath.DIRECTORY_SEPARATOR.'active.txt', 'version-1.0.0');
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => $activePath,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => $this->manifest(),
        ]);

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-1.1.0.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => json_encode($this->manifest(version: '1.1.0'), JSON_THROW_ON_ERROR),
            'Blog/active.txt' => 'version-1.1.0',
        ]);

        $release = app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleReleaseManager::class)->activateApproved('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $release->refresh();
        $this->assertSame('1.1.0', $module->version);
        $this->assertSame('enabled', $module->status);
        $this->assertSame($release->id, $module->active_release_id);
        $this->assertNull($module->pending_release_id);
        $this->assertSame($release->artifact_path, $module->path);
        $this->assertSame('version-1.1.0', file_get_contents($module->path.DIRECTORY_SEPARATOR.'active.txt'));
        $this->assertSame('active', $release->status);
        $this->assertSame(7, $release->reviewed_by);
        $this->assertNotNull($release->signature_hash);
    }

    public function test_failed_activation_compensates_migrations_when_menu_sync_fails(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        DB::table('system_menu')->insert([
            'pid' => 0,
            'title' => '宿主冲突菜单',
            'icon' => '',
            'href' => 'blog/conflict/index',
            'target' => '_self',
            'sort' => 0,
            'status' => 1,
            'create_time' => time(),
        ]);
        $migration = <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('module_activation_compensation', fn ($table) => $table->id());
    }
    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('module_activation_compensation');
    }
};
PHP;
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-activation-failure.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => json_encode($this->manifest(menus: [[
                'title' => '冲突菜单',
                'href' => 'blog/conflict/index',
            ]]), JSON_THROW_ON_ERROR),
            'Blog/database/migrations/2026_07_14_000010_activation_compensation.php' => $migration,
        ]);
        app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);
        app(ModuleReviewService::class)->approve('blog', 7);

        try {
            app(ModuleInstaller::class)->install('blog', 7);
            $this->fail('Expected menu ownership conflict to fail activation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('已被其他菜单占用', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('module_activation_compensation'));
        $this->assertDatabaseMissing('system_module_migration', [
            'module' => 'blog',
            'migration' => '2026_07_14_000010_activation_compensation.php',
        ]);
    }

    public function test_local_review_stages_and_signs_the_exact_artifact(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        $manifest = ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json');
        app(ModuleRepository::class)->upsertDiscovered($manifest);

        app(ModuleReviewService::class)->approve('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $release = SystemModuleRelease::query()->findOrFail($module->pending_release_id);
        $this->assertSame('approved', $release->status);
        $this->assertSame('local', $release->source_type);
        $this->assertSame('private', $release->trust_level);
        $this->assertSame(7, $release->reviewed_by);
        $this->assertNotNull($release->signature_hash);
        $this->assertNotSame($path, $release->artifact_path);
        $this->assertSame(
            $release->artifact_hash,
            app(ModuleArtifactHasher::class)->hashDirectory($release->artifact_path)
        );
    }

    public function test_install_activates_the_approved_pending_release(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        app(ModuleRepository::class)->upsertDiscovered(
            ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
        );
        app(ModuleReviewService::class)->approve('blog', 7);

        app(ModuleInstaller::class)->install('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $release = SystemModuleRelease::query()->findOrFail($module->active_release_id);
        $this->assertSame('installed', $module->status);
        $this->assertSame('active', $release->status);
        $this->assertNull($module->pending_release_id);
        $this->assertSame($release->artifact_path, $module->path);
    }

    public function test_discovery_cannot_overwrite_an_active_release_pointer(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        $repository = app(ModuleRepository::class);
        $repository->upsertDiscovered(ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json'));
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);
        $active = SystemModule::query()->where('name', 'blog')->firstOrFail();

        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'module.json',
            json_encode($this->manifest(version: '1.1.0'), JSON_THROW_ON_ERROR)
        );
        $repository->upsertDiscovered(ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json'));

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $this->assertSame('1.0.0', $module->version);
        $this->assertSame($active->path, $module->path);
        $this->assertSame($active->active_release_id, $module->active_release_id);
    }

    public function test_production_rejects_local_directory_upgrade(): void
    {
        $path = $this->writeModule('Blog', $this->manifest(version: '1.1.0'));
        SystemModule::query()->create([
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'disabled',
            'path' => $path,
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => $this->manifest(version: '1.0.0'),
        ]);
        $this->app['env'] = 'production';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('生产环境');

        app(ModuleUpgrader::class)->upgradeLocal('blog', 7);
    }

    public function test_tampered_active_release_is_not_loaded(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        app(ModuleRepository::class)->upsertDiscovered(
            ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
        );
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);
        app(ModuleInstaller::class)->enable('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        file_put_contents($module->path.DIRECTORY_SEPARATOR.'tampered.php', '<?php return false;');

        $this->assertArrayNotHasKey('blog', app(ModuleManager::class)->enabled());
        $this->assertStringContainsString(
            '完整性',
            (string) SystemModule::query()->where('name', 'blog')->value('last_error')
        );
    }

    public function test_module_health_bypasses_the_runtime_integrity_cache(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        app(ModuleRepository::class)->upsertDiscovered(
            ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
        );
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);
        app(ModuleInstaller::class)->enable('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $this->assertArrayHasKey('blog', app(ModuleManager::class)->enabled());
        file_put_contents($module->path.DIRECTORY_SEPARATOR.'tampered.php', '<?php return false;');

        $this->assertArrayHasKey('blog', app(ModuleManager::class)->enabled());
        $this->artisan('system:module-health')->assertExitCode(1);
        $this->assertStringContainsString(
            '完整性',
            (string) SystemModule::query()->where('name', 'blog')->value('last_error')
        );
    }

    public function test_rollback_switches_to_the_previous_approved_release(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $path = $this->writeModule('Blog', $this->manifest());
        file_put_contents($path.DIRECTORY_SEPARATOR.'active.txt', 'version-1.0.0');
        app(ModuleRepository::class)->upsertDiscovered(
            ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
        );
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);
        $firstReleaseId = SystemModule::query()->where('name', 'blog')->value('active_release_id');

        $zipPath = $this->root.DIRECTORY_SEPARATOR.'blog-1.1.0.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => json_encode($this->manifest(version: '1.1.0'), JSON_THROW_ON_ERROR),
            'Blog/active.txt' => 'version-1.1.0',
        ]);
        app(ModuleReleaseManager::class)->stageZip($zipPath, 'blog', 9);
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);

        app(ModuleRollbacker::class)->rollback('blog', 7);

        $module = SystemModule::query()->where('name', 'blog')->firstOrFail();
        $this->assertSame('1.0.0', $module->version);
        $this->assertSame((int) $firstReleaseId, (int) $module->active_release_id);
        $this->assertSame('version-1.0.0', file_get_contents($module->path.DIRECTORY_SEPARATOR.'active.txt'));
        $this->assertSame('active', SystemModuleRelease::query()->findOrFail($firstReleaseId)->status);
    }

    public function test_failed_migration_batch_compensates_earlier_migrations(): void
    {
        $path = $this->writeModule('Blog', $this->manifest());
        $migrationPath = $path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        mkdir($migrationPath, 0777, true);
        file_put_contents($migrationPath.DIRECTORY_SEPARATOR.'2026_07_14_000001_first.php', <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('module_compensation_first', fn ($table) => $table->id());
    }
    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('module_compensation_first');
    }
};
PHP);
        file_put_contents($migrationPath.DIRECTORY_SEPARATOR.'2026_07_14_000002_second.php', <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        throw new RuntimeException('second migration failed');
    }
    public function down(): void {}
};
PHP);

        try {
            app(ModuleMigrationRunner::class)->runPending(
                ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
            );
            $this->fail('Expected the second migration to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('second migration failed', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('module_compensation_first'));
        $this->assertDatabaseMissing('system_module_migration', ['module' => 'blog']);
    }

    public function test_module_menu_visibility_only_changes_owned_menus(): void
    {
        $hostMenuId = DB::table('system_menu')->insertGetId([
            'pid' => 0,
            'title' => '系统用户',
            'icon' => 'fa fa-users',
            'href' => 'system/user/index',
            'target' => '_self',
            'sort' => 0,
            'status' => 1,
            'create_time' => time(),
        ]);
        $path = $this->writeModule('Blog', $this->manifest(menus: [[
            'title' => '博客管理',
            'icon' => 'fa fa-book',
            'href' => 'blog/post/index',
        ]]));
        $manifest = ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json');

        $menus = app(ModuleMenuSynchronizer::class);
        $menus->sync($manifest);
        $ownedMenuId = (int) DB::table('system_module_menu')->where('module', 'blog')->value('menu_id');
        $this->assertGreaterThan(0, $ownedMenuId);
        $this->assertSame(1, (int) DB::table('system_menu')->where('id', $ownedMenuId)->value('status'));

        $menus->hide('blog');

        $this->assertSame(0, (int) DB::table('system_menu')->where('id', $ownedMenuId)->value('status'));
        $this->assertSame(1, (int) DB::table('system_menu')->where('id', $hostMenuId)->value('status'));

        $menus->sync($manifest);
        $this->assertSame(1, (int) DB::table('system_menu')->where('id', $ownedMenuId)->value('status'));
    }

    public function test_module_lifecycle_hides_and_restores_owned_menus(): void
    {
        $path = $this->writeModule('Blog', $this->manifest(menus: [[
            'title' => '博客管理',
            'href' => 'blog/post/index',
        ]]));
        app(ModuleRepository::class)->upsertDiscovered(
            ModuleManifest::fromFile($path.DIRECTORY_SEPARATOR.'module.json')
        );
        app(ModuleReviewService::class)->approve('blog', 7);
        app(ModuleInstaller::class)->install('blog', 7);
        app(ModuleInstaller::class)->enable('blog', 7);

        $menuId = (int) DB::table('system_module_menu')->where('module', 'blog')->value('menu_id');
        $this->assertGreaterThan(0, $menuId);
        $this->assertSame(1, (int) DB::table('system_menu')->where('id', $menuId)->value('status'));

        app(ModuleInstaller::class)->disable('blog', 7);
        $this->assertSame(0, (int) DB::table('system_menu')->where('id', $menuId)->value('status'));

        app(ModuleInstaller::class)->enable('blog', 7);
        $this->assertSame(1, (int) DB::table('system_menu')->where('id', $menuId)->value('status'));

        app(ModuleInstaller::class)->uninstallPreserve('blog', 7);
        $this->assertSame(0, (int) DB::table('system_menu')->where('id', $menuId)->value('status'));
    }

    private function writeModule(string $directory, array $manifest): string
    {
        $path = $this->root.DIRECTORY_SEPARATOR.$directory;
        mkdir($path, 0777, true);
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $path;
    }

    private function manifest(
        string $type = 'private',
        array $apiAbilities = ['profile:read'],
        string $version = '1.0.0',
        array $menus = [],
        array $apiQuotas = []
    ): array {
        return [
            'schema_version' => '1.0',
            'name' => 'blog',
            'title' => 'Blog',
            'vendor' => 'tests',
            'version' => $version,
            'type' => $type,
            'core_version' => '^8.0',
            'php' => '>=8.3',
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
            'permissions' => ['menu:write'],
            'external_domains' => [],
            'dependencies' => [],
            'conflicts' => [],
            'api' => ['abilities' => $apiAbilities, 'quotas' => $apiQuotas],
            'menus' => $menus,
        ];
    }

    private function createZip(string $path, array $files): void
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();
    }

    private function deletePath(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child)) {
                $this->deletePath($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
