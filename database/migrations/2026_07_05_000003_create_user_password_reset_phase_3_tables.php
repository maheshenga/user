<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_password_reset', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('account_type', 32)->index();
            $table->string('account', 180)->index();
            $table->string('token_hash', 64)->default('');
            $table->string('code_hash', 64)->default('');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->string('request_ip', 45)->default('');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });

        Schema::create('user_security_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event', 80)->index();
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 500)->default('');
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_security_log');
        Schema::dropIfExists('user_password_reset');
    }
};
