<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('user_api_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('user_account')->cascadeOnDelete();
            $table->string('module', 80)->index();
            $table->string('device_id', 128);
            $table->string('device_name', 160)->default('Qingyu Desktop');
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('last_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'module', 'device_id'], 'user_api_session_device_unique');
        });

        Schema::create('user_api_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('user_api_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_refresh_tokens');
        Schema::dropIfExists('user_api_sessions');
        Schema::dropIfExists('personal_access_tokens');
    }
};
