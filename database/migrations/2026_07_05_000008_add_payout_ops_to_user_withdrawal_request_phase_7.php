<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_withdrawal_request', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_admin_id')->nullable()->after('audit_admin_id')->index();
            $table->timestamp('approved_at')->nullable()->after('approved_admin_id');
            $table->unsignedBigInteger('payout_admin_id')->nullable()->after('approved_at')->index();
            $table->string('payout_method', 40)->default('')->after('payout_admin_id')->index();
            $table->string('payout_transaction_id', 120)->default('')->after('payout_method')->index();
            $table->json('payout_proof_json')->nullable()->after('payout_transaction_id');
            $table->string('payout_error', 1000)->default('')->after('payout_proof_json');
            $table->unsignedInteger('payout_attempt_count')->default(0)->after('payout_error');
            $table->timestamp('payout_last_attempt_at')->nullable()->after('payout_attempt_count');
            $table->timestamp('paid_at')->nullable()->after('payout_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_withdrawal_request', function (Blueprint $table): void {
            $table->dropColumn([
                'approved_admin_id',
                'approved_at',
                'payout_admin_id',
                'payout_method',
                'payout_transaction_id',
                'payout_proof_json',
                'payout_error',
                'payout_attempt_count',
                'payout_last_attempt_at',
                'paid_at',
            ]);
        });
    }
};
