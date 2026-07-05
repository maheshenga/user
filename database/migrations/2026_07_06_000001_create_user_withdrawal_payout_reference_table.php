<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_withdrawal_payout_reference', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('withdrawal_id')->unique();
            $table->string('payout_method', 40);
            $table->string('payout_transaction_id', 120);
            $table->string('reference_key', 64)->unique();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedBigInteger('create_time')->nullable();

            $table->index(['payout_method', 'payout_transaction_id'], 'withdrawal_payout_reference_lookup');
        });

        $this->backfillExistingPaidWithdrawals();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_withdrawal_payout_reference');
    }

    public function backfillExistingPaidWithdrawals(): void
    {
        DB::table('user_withdrawal_request')
            ->where('status', 'paid')
            ->where('payout_method', '<>', '')
            ->where('payout_transaction_id', '<>', '')
            ->orderBy('id')
            ->get()
            ->each(function ($withdrawal): void {
                DB::table('user_withdrawal_payout_reference')->updateOrInsert(
                    ['withdrawal_id' => $withdrawal->id],
                    [
                        'payout_method' => $withdrawal->payout_method,
                        'payout_transaction_id' => $withdrawal->payout_transaction_id,
                        'reference_key' => hash('sha256', $withdrawal->payout_method."\0".$withdrawal->payout_transaction_id),
                        'admin_id' => $withdrawal->payout_admin_id,
                        'create_time' => time(),
                    ]
                );
            });
    }
};
