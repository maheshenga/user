<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE_UNIQUE_INDEX = 'user_vip_record_source_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('activation_code_batch', 'vip_level')) {
            Schema::table('activation_code_batch', function (Blueprint $table): void {
                $table->unsignedInteger('vip_level')->default(0)->after('vip_plan_id');
            });
        }

        if (! Schema::hasColumn('user_vip_record', 'vip_level')) {
            Schema::table('user_vip_record', function (Blueprint $table): void {
                $table->unsignedInteger('vip_level')->default(0)->after('vip_plan_id');
            });
        }

        $this->backfillVipLevels('activation_code_batch');
        $this->backfillVipLevels('user_vip_record');
        $this->assertSnapshotsBackfilled('activation_code_batch');
        $this->assertSnapshotsBackfilled('user_vip_record');
        $this->assertVipSourcesUnique();

        if (! Schema::hasIndex('user_vip_record', self::SOURCE_UNIQUE_INDEX)) {
            Schema::table('user_vip_record', function (Blueprint $table): void {
                $table->unique(['source_type', 'source_id'], self::SOURCE_UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('user_vip_record', self::SOURCE_UNIQUE_INDEX)) {
            Schema::table('user_vip_record', function (Blueprint $table): void {
                $table->dropUnique(self::SOURCE_UNIQUE_INDEX);
            });
        }

        if (Schema::hasColumn('user_vip_record', 'vip_level')) {
            Schema::table('user_vip_record', function (Blueprint $table): void {
                $table->dropColumn('vip_level');
            });
        }

        if (Schema::hasColumn('activation_code_batch', 'vip_level')) {
            Schema::table('activation_code_batch', function (Blueprint $table): void {
                $table->dropColumn('vip_level');
            });
        }
    }

    private function backfillVipLevels(string $table): void
    {
        DB::table($table)
            ->where('vip_level', 0)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $level = (int) DB::table('vip_plan')->where('id', $row->vip_plan_id)->value('level');
                    if ($level > 0) {
                        DB::table($table)->where('id', $row->id)->update(['vip_level' => $level]);
                    }
                }
            });
    }

    private function assertSnapshotsBackfilled(string $table): void
    {
        $invalid = DB::table($table)->where('vip_level', '<=', 0)->value('id');
        if ($invalid !== null) {
            throw new RuntimeException("Cannot backfill VIP level snapshot for {$table} row {$invalid}.");
        }
    }

    private function assertVipSourcesUnique(): void
    {
        $duplicate = DB::table('user_vip_record')
            ->select(['source_type', 'source_id'])
            ->groupBy('source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                "Duplicate VIP grant source must be repaired before migration: {$duplicate->source_type}:{$duplicate->source_id}."
            );
        }
    }
};
