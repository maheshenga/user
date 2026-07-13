<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('qingyu_ip_agent_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 40)->default('string');
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qingyu_ip_agent_settings');
    }
};
