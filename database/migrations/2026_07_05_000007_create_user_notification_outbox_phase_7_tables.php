<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type', 80)->index();
            $table->string('channel', 32)->index();
            $table->string('recipient', 180);
            $table->string('recipient_mask', 180)->default('');
            $table->string('subject', 180)->default('');
            $table->longText('payload_json');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->integer('create_time')->default(0)->index();
            $table->integer('update_time')->default(0);
            $table->index(['type', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_outbox');
    }
};
