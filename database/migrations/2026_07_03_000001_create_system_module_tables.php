<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('system_module_source');
        Schema::dropIfExists('system_module_log');
        Schema::dropIfExists('system_module_migration');
        Schema::dropIfExists('system_module_version');
        Schema::dropIfExists('system_module');
    }
};
