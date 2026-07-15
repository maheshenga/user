<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_outbox', function (Blueprint $table): void {
            $table->timestamp('locked_at')->nullable()->after('available_at')->index();
            $table->string('lock_token', 64)->nullable()->after('locked_at')->index();
            $table->timestamp('failed_at')->nullable()->after('sent_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_outbox', function (Blueprint $table): void {
            $table->dropIndex(['locked_at']);
            $table->dropIndex(['lock_token']);
            $table->dropIndex(['failed_at']);
        });

        Schema::table('user_notification_outbox', function (Blueprint $table): void {
            $table->dropColumn(['locked_at', 'lock_token', 'failed_at']);
        });
    }
};
