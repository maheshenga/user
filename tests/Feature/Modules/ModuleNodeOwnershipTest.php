<?php

namespace Tests\Feature\Modules;

use App\Http\Services\AuthService;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManifest;
use App\Modules\ModuleNodeSynchronizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleNodeOwnershipTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.path', base_path('tests/Fixtures/modules'));
    }

    public function test_system_nodes_have_module_ownership_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('system_node', [
            'owner_module',
            'managed_hash',
            'status',
        ]));
    }

    public function test_ownership_migration_backfills_legacy_module_nodes_by_prefix(): void
    {
        Schema::drop('system_node');
        Schema::create('system_node', function (Blueprint $table): void {
            $table->id();
            $table->string('node', 255)->unique();
            $table->string('title', 120)->nullable();
            $table->unsignedTinyInteger('type')->default(2);
            $table->unsignedTinyInteger('is_auth')->default(1);
        });
        DB::table('system_node')->insert([
            ['node' => 'blog/post', 'title' => 'Blog', 'type' => 1, 'is_auth' => 1],
            ['node' => 'system/config/index', 'title' => 'System', 'type' => 2, 'is_auth' => 1],
        ]);
        DB::table('system_module')->insert([
            'name' => 'blog',
            'title' => 'Blog Module',
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('tests/Fixtures/modules/Blog'),
            'namespace' => 'Modules\\Blog',
            'admin_prefix' => 'blog',
            'config_json' => '{}',
        ]);

        $migration = require base_path('database/migrations/2026_07_15_000006_add_module_node_ownership.php');
        $migration->up();

        $this->assertSame('blog', DB::table('system_node')->where('node', 'blog/post')->value('owner_module'));
        $this->assertSame('core', DB::table('system_node')->where('node', 'system/config/index')->value('owner_module'));
        $this->assertSame(1, (int) DB::table('system_node')->where('node', 'blog/post')->value('status'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('system_node', 'owner_module'));
        $this->assertFalse(Schema::hasColumn('system_node', 'managed_hash'));
        $this->assertFalse(Schema::hasColumn('system_node', 'status'));
    }

    public function test_sync_claims_nodes_and_hide_preserves_role_mappings(): void
    {
        $this->assertTrue(class_exists(ModuleNodeSynchronizer::class));
        $manifest = $this->blogManifest();
        $synchronizer = app(ModuleNodeSynchronizer::class);

        $count = $synchronizer->sync($manifest);

        $this->assertGreaterThanOrEqual(2, $count);
        $controller = DB::table('system_node')->where('node', 'blog/post')->first();
        $action = DB::table('system_node')->where('node', 'blog/post/index')->first();
        $this->assertNotNull($controller);
        $this->assertNotNull($action);
        $this->assertSame('blog', $controller->owner_module);
        $this->assertSame(1, (int) $controller->status);
        $this->assertNotNull($controller->managed_hash);

        DB::table('system_auth_node')->insert([
            'auth_id' => 1,
            'node_id' => $action->id,
        ]);

        $hidden = $synchronizer->hide('blog');

        $this->assertSame($count, $hidden);
        $this->assertSame(0, (int) DB::table('system_node')->where('id', $action->id)->value('status'));
        $this->assertDatabaseHas('system_auth_node', [
            'auth_id' => 1,
            'node_id' => $action->id,
        ]);

        $synchronizer->sync($manifest);
        $this->assertSame($action->id, DB::table('system_node')->where('node', 'blog/post/index')->value('id'));
        $this->assertSame(1, (int) DB::table('system_node')->where('id', $action->id)->value('status'));
    }

    public function test_sync_hides_stale_owned_nodes(): void
    {
        $this->assertTrue(class_exists(ModuleNodeSynchronizer::class));
        DB::table('system_node')->insert([
            'owner_module' => 'blog',
            'managed_hash' => hash('sha256', 'stale'),
            'node' => 'blog/stale/index',
            'title' => 'Stale',
            'type' => 2,
            'is_auth' => 1,
            'status' => 1,
        ]);

        app(ModuleNodeSynchronizer::class)->sync($this->blogManifest());

        $this->assertSame(0, (int) DB::table('system_node')
            ->where('node', 'blog/stale/index')
            ->value('status'));
    }

    public function test_sync_rejects_cross_module_node_claims(): void
    {
        $this->assertTrue(class_exists(ModuleNodeSynchronizer::class));
        DB::table('system_node')->insert([
            'owner_module' => 'other_module',
            'managed_hash' => hash('sha256', 'other'),
            'node' => 'blog/post',
            'title' => 'Other controller',
            'type' => 1,
            'is_auth' => 1,
            'status' => 1,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already belongs to module [other_module]');

        app(ModuleNodeSynchronizer::class)->sync($this->blogManifest());
    }

    public function test_hidden_module_nodes_are_excluded_from_admin_authorization(): void
    {
        $synchronizer = app(ModuleNodeSynchronizer::class);
        $synchronizer->sync($this->blogManifest());
        $nodeId = DB::table('system_node')->where('node', 'blog/post/index')->value('id');
        DB::table('system_admin')->where('id', 1)->update(['auth_ids' => '1']);
        DB::table('system_auth_node')->insert([
            'auth_id' => 1,
            'node_id' => $nodeId,
        ]);

        $this->assertArrayHasKey('blog/post/index', (new AuthService(1))->getNodeList());

        $synchronizer->hide('blog');

        $this->assertArrayNotHasKey('blog/post/index', (new AuthService(1))->getNodeList());
        $this->assertDatabaseHas('system_auth_node', [
            'auth_id' => 1,
            'node_id' => $nodeId,
        ]);
    }

    public function test_module_lifecycle_syncs_and_hides_owned_nodes(): void
    {
        $this->installApprovedModule('blog', 1);

        $this->assertDatabaseHas('system_node', [
            'owner_module' => 'blog',
            'node' => 'blog/post/index',
            'status' => 1,
        ]);

        app(ModuleInstaller::class)->enable('blog', 1);
        app(ModuleInstaller::class)->disable('blog', 1);

        $this->assertDatabaseHas('system_node', [
            'owner_module' => 'blog',
            'node' => 'blog/post/index',
            'status' => 0,
        ]);
    }

    private function blogManifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(base_path('tests/Fixtures/modules/Blog/module.json'));
    }
}
