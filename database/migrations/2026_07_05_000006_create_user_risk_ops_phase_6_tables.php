<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_risk_event', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('category', 40);
            $table->string('event_type', 80);
            $table->string('severity', 32)->default('low');
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('ip', 45)->default('')->index();
            $table->string('status', 32)->default('open')->index();
            $table->json('detail_json')->nullable();
            $table->unsignedBigInteger('review_admin_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('create_time')->nullable()->index();
            $table->unsignedBigInteger('update_time')->nullable();

            $table->index(['category', 'event_type']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('user_withdrawal_request', function (Blueprint $table) {
            $table->id();
            $table->string('withdrawal_no', 40)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->string('request_ip', 45)->default('');
            $table->json('account_snapshot_json')->nullable();
            $table->unsignedBigInteger('ledger_freeze_id')->nullable()->index();
            $table->unsignedBigInteger('ledger_success_id')->nullable()->index();
            $table->string('reason', 500)->default('');
            $table->unsignedBigInteger('audit_admin_id')->nullable()->index();
            $table->timestamp('audited_at')->nullable();
            $table->unsignedBigInteger('create_time')->nullable()->index();
            $table->unsignedBigInteger('update_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_withdrawal_request');
        Schema::dropIfExists('user_risk_event');
    }
};
