<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_membership', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('user_account')->cascadeOnDelete();
            $table->string('module', 80);
            $table->string('status', 30)->default('active');
            $table->string('join_source', 80);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'module'], 'user_module_membership_unique');
            $table->index(['module', 'status', 'user_id'], 'user_module_membership_module_index');
        });

        Schema::create('module_registration_ticket', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module', 80)->index();
            $table->string('token_hash', 64)->unique();
            $table->json('claims_json');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();
        });

        if (Schema::hasColumn('user_account', 'source_module')) {
            DB::table('user_account')
                ->select(['id', 'source_module'])
                ->whereNotNull('source_module')
                ->where('source_module', '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($users): void {
                    $now = now();
                    $rows = [];
                    foreach ($users as $user) {
                        $rows[] = [
                            'user_id' => (int) $user->id,
                            'module' => (string) $user->source_module,
                            'status' => 'active',
                            'join_source' => 'attribution_backfill',
                            'granted_by' => null,
                            'joined_at' => $now,
                            'revoked_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($rows !== []) {
                        DB::table('user_module_membership')->insertOrIgnore($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_registration_ticket');
        Schema::dropIfExists('user_module_membership');
    }
};
