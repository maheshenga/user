<?php

namespace Tests\Feature\Admin;

use App\Http\Services\AuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createAuthTables();
    }

    public function test_auth_service_handles_missing_admin_row_without_type_error(): void
    {
        DB::table('system_node')->insert([
            'node' => 'admin.index/index',
            'title' => 'Dashboard',
            'type' => 2,
            'is_auth' => 1,
        ]);

        $service = new AuthService(999999);

        $this->assertSame([], $service->getAdminInfo());
        $this->assertSame([], $service->getAdminNode());
        $this->assertFalse($service->checkNode('admin.index/index'));
    }

    private function createAuthTables(): void
    {
        Schema::create('system_admin', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('auth_ids', 255)->nullable();
        });

        Schema::create('system_node', function (Blueprint $table): void {
            $table->id();
            $table->string('node', 255)->unique();
            $table->string('title', 120)->nullable();
            $table->unsignedTinyInteger('type')->default(2);
            $table->unsignedTinyInteger('is_auth')->default(1);
        });

        Schema::create('system_auth_node', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('auth_id');
            $table->unsignedBigInteger('node_id');
        });
    }
}
