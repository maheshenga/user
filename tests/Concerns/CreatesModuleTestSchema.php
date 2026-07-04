<?php

namespace Tests\Concerns;

use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRepository;
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
                $table->string('icon', 120)->default('');
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

    protected function approveModuleForInstall(string $name, ?int $actorId = 1): void
    {
        $manifest = app(ModuleManager::class)->manifest($name);
        $this->assertNotNull($manifest, "Test module [{$name}] manifest should exist.");

        $repository = app(ModuleRepository::class);
        $repository->upsertDiscovered($manifest);
        $repository->approve($name, $actorId);
    }

    protected function installApprovedModule(string $name, ?int $actorId = null): void
    {
        $this->approveModuleForInstall($name, $actorId ?? 1);
        app(ModuleInstaller::class)->install($name, $actorId);
    }
}
