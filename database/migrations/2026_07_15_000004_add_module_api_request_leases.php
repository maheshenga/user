<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_api_request', function (Blueprint $table): void {
            $table->string('lease_token', 64)->nullable()->after('status')->index();
            $table->timestamp('lease_expires_at')->nullable()->after('lease_token')->index();
            $table->unsignedInteger('attempt_count')->default(1)->after('lease_expires_at');
        });

        DB::table('module_api_request')
            ->where('status', 'processing')
            ->update([
                'lease_token' => null,
                'lease_expires_at' => now()->subSecond(),
                'attempt_count' => 1,
            ]);
    }

    public function down(): void
    {
        Schema::table('module_api_request', function (Blueprint $table): void {
            $table->dropIndex(['lease_token']);
            $table->dropIndex(['lease_expires_at']);
        });

        Schema::table('module_api_request', function (Blueprint $table): void {
            $table->dropColumn(['lease_token', 'lease_expires_at', 'attempt_count']);
        });
    }
};
