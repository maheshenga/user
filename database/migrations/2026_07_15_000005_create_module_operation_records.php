<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_module_operation', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module', 80)->index();
            $table->string('active_key', 80)->nullable()->unique();
            $table->string('action', 80);
            $table->string('previous_status', 40)->nullable();
            $table->string('target_status', 40)->nullable();
            $table->string('recoverable_status', 40)->nullable();
            $table->string('stage', 80)->default('claimed');
            $table->string('status', 40)->default('running')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('heartbeat_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['module', 'status', 'heartbeat_at'], 'module_operation_recovery_index');
        });

        Schema::table('system_module', function (Blueprint $table): void {
            $table->uuid('active_operation_id')->nullable()->after('pending_release_id')->index();
            $table->timestamp('operation_started_at')->nullable()->after('active_operation_id');
            $table->string('recoverable_status', 40)->nullable()->after('operation_started_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('system_module')) {
            Schema::table('system_module', function (Blueprint $table): void {
                $table->dropColumn([
                    'active_operation_id',
                    'operation_started_at',
                    'recoverable_status',
                ]);
            });
        }

        Schema::dropIfExists('system_module_operation');
    }
};
