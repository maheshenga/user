<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_module_release', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 80)->index();
            $table->string('version', 40);
            $table->string('source_type', 40)->default('local');
            $table->string('trust_level', 40)->default('private');
            $table->string('artifact_path', 500);
            $table->string('artifact_hash', 64);
            $table->string('signature_hash', 64)->nullable();
            $table->json('manifest_json');
            $table->string('status', 40)->default('pending_review')->index();
            $table->string('previous_status', 40)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['module', 'version', 'artifact_hash'], 'module_release_artifact_unique');
            $table->index(['module', 'status', 'id'], 'module_release_status_index');
        });

        Schema::table('system_module', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_release_id')->nullable()->after('signature_hash')->index();
            $table->unsignedBigInteger('pending_release_id')->nullable()->after('active_release_id')->index();
        });

        Schema::create('system_module_menu', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 80)->index();
            $table->unsignedBigInteger('menu_id')->unique();
            $table->string('menu_key', 255);
            $table->string('managed_hash', 64);
            $table->timestamps();

            $table->unique(['module', 'menu_key'], 'module_menu_key_unique');
        });

        Schema::create('module_api_request', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 80);
            $table->foreignId('user_id')->constrained('user_account')->cascadeOnDelete();
            $table->string('operation', 120);
            $table->string('request_id', 80);
            $table->string('request_hash', 64);
            $table->string('status', 30)->default('processing');
            $table->json('response_json')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['module', 'user_id', 'operation', 'request_id'], 'module_api_request_unique');
            $table->index(['module', 'user_id', 'operation', 'created_at'], 'module_api_quota_index');
        });

        if (Schema::hasTable('activation_code_batch') && ! Schema::hasColumn('activation_code_batch', 'owner_module')) {
            Schema::table('activation_code_batch', function (Blueprint $table): void {
                $table->string('owner_module', 80)->default('core')->after('id')->index();
            });
        }

        if (Schema::hasTable('activation_code_redemption') && ! Schema::hasColumn('activation_code_redemption', 'owner_module')) {
            Schema::table('activation_code_redemption', function (Blueprint $table): void {
                $table->string('owner_module', 80)->default('core')->after('id')->index();
            });

            if (Schema::hasTable('qingyu_ip_agent_operation_logs')) {
                $batchIds = DB::table('qingyu_ip_agent_operation_logs')
                    ->where('action', 'activation_code.create_batch')
                    ->where('target_type', 'activation_code_batch')
                    ->where('result', 'success')
                    ->whereNotNull('target_id')
                    ->pluck('target_id');

                if ($batchIds->isNotEmpty()) {
                    DB::table('activation_code_batch')
                        ->whereIn('id', $batchIds)
                        ->update(['owner_module' => 'qingyu_ip_agent']);
                    DB::table('activation_code_redemption')
                        ->whereIn('batch_id', $batchIds)
                        ->update(['owner_module' => 'qingyu_ip_agent']);
                }
            }
        }

        if (Schema::hasTable('qingyu_ip_agent_operation_logs') && ! Schema::hasColumn('qingyu_ip_agent_operation_logs', 'request_id')) {
            Schema::table('qingyu_ip_agent_operation_logs', function (Blueprint $table): void {
                $table->string('request_id', 80)->nullable()->after('admin_id')->index();
                $table->string('error_code', 80)->nullable()->after('result')->index();
                $table->unsignedInteger('duration_ms')->nullable()->after('error_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('qingyu_ip_agent_operation_logs') && Schema::hasColumn('qingyu_ip_agent_operation_logs', 'request_id')) {
            Schema::table('qingyu_ip_agent_operation_logs', function (Blueprint $table): void {
                $table->dropColumn(['request_id', 'error_code', 'duration_ms']);
            });
        }

        if (Schema::hasTable('activation_code_redemption') && Schema::hasColumn('activation_code_redemption', 'owner_module')) {
            Schema::table('activation_code_redemption', function (Blueprint $table): void {
                $table->dropColumn('owner_module');
            });
        }

        if (Schema::hasTable('activation_code_batch') && Schema::hasColumn('activation_code_batch', 'owner_module')) {
            Schema::table('activation_code_batch', function (Blueprint $table): void {
                $table->dropColumn('owner_module');
            });
        }

        Schema::dropIfExists('system_module_menu');
        Schema::dropIfExists('module_api_request');

        if (Schema::hasTable('system_module')) {
            Schema::table('system_module', function (Blueprint $table): void {
                $table->dropColumn(['active_release_id', 'pending_release_id']);
            });
        }

        Schema::dropIfExists('system_module_release');
    }
};
