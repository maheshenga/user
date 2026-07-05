<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invite_code', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('code', 40)->unique();
            $table->string('type', 32)->default('user')->index();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('max_uses')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });

        Schema::create('user_invite_relation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('parent_user_id')->index();
            $table->unsignedBigInteger('grandparent_user_id')->nullable()->index();
            $table->unsignedBigInteger('invite_code_id')->index();
            $table->string('level_path', 255)->default('');
            $table->string('bind_type', 32)->default('register');
            $table->string('status', 32)->default('active')->index();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invite_relation');
        Schema::dropIfExists('user_invite_code');
    }
};
