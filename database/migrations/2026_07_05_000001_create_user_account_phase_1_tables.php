<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 32)->nullable()->unique();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('email', 180)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('nickname', 120)->default('');
            $table->string('avatar', 500)->default('');
            $table->string('status', 32)->default('active')->index();
            $table->string('register_channel', 80)->default('');
            $table->string('register_ip', 45)->default('');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->default('');
            $table->decimal('available_balance', 12, 2)->default(0);
            $table->decimal('frozen_balance', 12, 2)->default(0);
            $table->unsignedInteger('vip_level')->default(0);
            $table->timestamp('vip_expires_at')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });

        Schema::create('user_profile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('real_name', 120)->default('');
            $table->string('company', 180)->default('');
            $table->string('country', 80)->default('');
            $table->string('province', 80)->default('');
            $table->string('city', 80)->default('');
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });

        Schema::create('user_login_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('account', 180)->default('');
            $table->string('login_type', 32)->default('');
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 500)->default('');
            $table->string('result', 32)->index();
            $table->string('error_message', 500)->default('');
            $table->unsignedBigInteger('create_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_log');
        Schema::dropIfExists('user_profile');
        Schema::dropIfExists('user_account');
    }
};
