<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_commission', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('buyer_user_id')->index();
            $table->unsignedBigInteger('beneficiary_user_id')->index();
            $table->unsignedTinyInteger('level');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->string('reason', 500)->default('');
            $table->unsignedBigInteger('audit_admin_id')->nullable()->index();
            $table->timestamp('audited_at')->nullable();
            $table->unsignedBigInteger('settled_ledger_id')->nullable()->index();
            $table->unsignedBigInteger('reversed_commission_id')->nullable()->index();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();

            $table->index(['source_type', 'source_id']);
            $table->unique(
                ['source_type', 'source_id', 'level', 'beneficiary_user_id'],
                'affiliate_commission_source_level_beneficiary_unique'
            );
        });

        Schema::create('user_balance_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('direction', 32);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->decimal('frozen_before', 12, 2)->default(0);
            $table->decimal('frozen_after', 12, 2)->default(0);
            $table->string('type', 64)->index();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('remark', 500)->default('');
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedBigInteger('create_time')->nullable()->index();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_balance_ledger');
        Schema::dropIfExists('affiliate_commission');
    }
};
