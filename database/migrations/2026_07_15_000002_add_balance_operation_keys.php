<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'user_balance_ledger_operation_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('user_balance_ledger', 'operation_key')) {
            Schema::table('user_balance_ledger', function (Blueprint $table): void {
                $table->string('operation_key', 64)->nullable()->after('source_id');
            });
        }

        $this->assertHistoricalOperationsUnique();
        $this->backfillOperationKeys();

        if (! Schema::hasIndex('user_balance_ledger', self::UNIQUE_INDEX)) {
            Schema::table('user_balance_ledger', function (Blueprint $table): void {
                $table->unique('operation_key', self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('user_balance_ledger', self::UNIQUE_INDEX)) {
            Schema::table('user_balance_ledger', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (Schema::hasColumn('user_balance_ledger', 'operation_key')) {
            Schema::table('user_balance_ledger', function (Blueprint $table): void {
                $table->dropColumn('operation_key');
            });
        }
    }

    private function assertHistoricalOperationsUnique(): void
    {
        $duplicate = DB::table('user_balance_ledger')
            ->select(['user_id', 'direction', 'type', 'source_id'])
            ->selectRaw('TRIM(source_type) AS source_type')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('user_id', 'direction', 'type', 'source_id')
            ->groupByRaw('TRIM(source_type)')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                "Duplicate balance operation must be repaired before migration: {$duplicate->user_id}:{$duplicate->direction}:{$duplicate->type}:{$duplicate->source_type}:{$duplicate->source_id}."
            );
        }
    }

    private function backfillOperationKeys(): void
    {
        DB::table('user_balance_ledger')
            ->whereNull('operation_key')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_encode(
                        [(int) $row->user_id, (string) $row->direction, (string) $row->type, trim((string) $row->source_type), (int) $row->source_id],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                    DB::table('user_balance_ledger')->where('id', $row->id)->update([
                        'operation_key' => hash('sha256', $payload),
                    ]);
                }
            });
    }
};
