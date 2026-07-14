<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qingyu_ip_agent_operation_logs') || Schema::hasColumn('qingyu_ip_agent_operation_logs', 'request_id')) {
            return;
        }

        Schema::table('qingyu_ip_agent_operation_logs', function (Blueprint $table): void {
            $table->string('request_id', 80)->nullable()->after('admin_id')->index();
            $table->string('error_code', 80)->nullable()->after('result')->index();
            $table->unsignedInteger('duration_ms')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('qingyu_ip_agent_operation_logs') || ! Schema::hasColumn('qingyu_ip_agent_operation_logs', 'request_id')) {
            return;
        }

        Schema::table('qingyu_ip_agent_operation_logs', function (Blueprint $table): void {
            $table->dropColumn(['request_id', 'error_code', 'duration_ms']);
        });
    }
};
