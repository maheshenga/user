<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vip_plan', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('level')->default(1)->index();
            $table->unsignedInteger('duration_days')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->boolean('is_commissionable')->default(false);
            $table->decimal('first_level_rate', 8, 4)->default(0);
            $table->decimal('second_level_rate', 8, 4)->default(0);
            $table->json('benefits_json')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });

        Schema::create('user_vip_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('source_type', 40)->index();
            $table->unsignedBigInteger('source_id')->default(0);
            $table->unsignedBigInteger('vip_plan_id')->index();
            $table->timestamp('before_expires_at')->nullable();
            $table->timestamp('after_expires_at')->nullable();
            $table->unsignedInteger('duration_days')->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('activation_code_batch', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedBigInteger('vip_plan_id')->index();
            $table->unsignedInteger('duration_days')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->boolean('is_commissionable')->default(false);
            $table->decimal('first_level_reward', 12, 2)->default(0);
            $table->decimal('second_level_reward', 12, 2)->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedBigInteger('create_admin_id')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });

        Schema::create('activation_code', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->index();
            $table->string('code_hash', 64)->unique();
            $table->string('display_code_tail', 12)->default('');
            $table->string('status', 32)->default('unused')->index();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedBigInteger('bound_user_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });

        Schema::create('activation_code_redemption', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activation_code_id')->nullable()->index();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('vip_record_id')->nullable()->index();
            $table->unsignedBigInteger('commission_source_id')->nullable();
            $table->string('redeem_ip', 45)->default('');
            $table->string('result', 32)->index();
            $table->string('error_message', 500)->default('');
            $table->unsignedBigInteger('create_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_code_redemption');
        Schema::dropIfExists('activation_code');
        Schema::dropIfExists('activation_code_batch');
        Schema::dropIfExists('user_vip_record');
        Schema::dropIfExists('vip_plan');
    }
};
