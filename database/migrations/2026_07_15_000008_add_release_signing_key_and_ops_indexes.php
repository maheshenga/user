<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_module_release', function (Blueprint $table): void {
            $table->string('key_id', 80)->nullable()->after('signature_hash');
            $table->index('key_id', 'module_release_key_index');
            $table->index(['status', 'created_at', 'id'], 'module_release_retention_index');
        });

        Schema::table('module_api_request', function (Blueprint $table): void {
            $table->index(['status', 'finished_at', 'id'], 'module_api_request_retention_index');
        });

        Schema::table('system_module_operation', function (Blueprint $table): void {
            $table->index(['status', 'finished_at', 'id'], 'module_operation_retention_index');
        });

        Schema::table('system_module_log', function (Blueprint $table): void {
            $table->index(['result', 'admin_id', 'finished_at', 'id'], 'module_log_retention_index');
        });

        Schema::table('user_api_sessions', function (Blueprint $table): void {
            $table->index(['revoked_at', 'id'], 'user_api_session_retention_index');
        });

        Schema::table('user_api_refresh_tokens', function (Blueprint $table): void {
            $table->index(['expires_at', 'updated_at', 'id'], 'user_api_refresh_retention_index');
        });

        Schema::table('user_notification_outbox', function (Blueprint $table): void {
            $table->index(['status', 'create_time', 'id'], 'notification_outbox_retention_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_outbox', function (Blueprint $table): void {
            $table->dropIndex('notification_outbox_retention_index');
        });
        Schema::table('user_api_refresh_tokens', function (Blueprint $table): void {
            $table->dropIndex('user_api_refresh_retention_index');
        });
        Schema::table('user_api_sessions', function (Blueprint $table): void {
            $table->dropIndex('user_api_session_retention_index');
        });
        Schema::table('system_module_log', function (Blueprint $table): void {
            $table->dropIndex('module_log_retention_index');
        });
        Schema::table('system_module_operation', function (Blueprint $table): void {
            $table->dropIndex('module_operation_retention_index');
        });
        Schema::table('module_api_request', function (Blueprint $table): void {
            $table->dropIndex('module_api_request_retention_index');
        });
        Schema::table('system_module_release', function (Blueprint $table): void {
            $table->dropIndex('module_release_retention_index');
            $table->dropIndex('module_release_key_index');
            $table->dropColumn('key_id');
        });
    }
};
