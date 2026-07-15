<?php

namespace Tests\Feature\Modules;

use App\Models\SystemModule;
use App\Models\SystemModuleOperation;
use App\Models\SystemModuleRelease;
use App\Modules\ModuleHealthInspector;
use App\Modules\ModuleRetentionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleOperationsTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.path', storage_path('framework/testing-module-operations'));
        Config::set('modules.signing_active_key_id', 'ops-v1');
        Config::set('modules.signing_keys', ['ops-v1' => str_repeat('o', 32)]);
    }

    public function test_operations_schema_has_retention_and_signing_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('system_module_release', 'key_id'));
        $this->assertTrue(Schema::hasIndex('system_module_release', 'module_release_retention_index'));
        $this->assertTrue(Schema::hasIndex('module_api_request', 'module_api_request_retention_index'));
        $this->assertTrue(Schema::hasIndex('system_module_operation', 'module_operation_retention_index'));
        $this->assertTrue(Schema::hasIndex('system_module_log', 'module_log_retention_index'));
    }

    public function test_health_inspector_collects_every_independent_module_issue(): void
    {
        $this->createModule('first_broken');
        $this->createModule('second_broken');

        $result = app(ModuleHealthInspector::class)->inspect();
        $missingReleaseIssues = array_values(array_filter(
            $result['issues'],
            static fn (array $issue): bool => $issue['code'] === 'active_release_missing'
        ));

        $this->assertFalse($result['ok']);
        $this->assertCount(2, $missingReleaseIssues);
        $this->assertSame(2, $result['metrics']['enabled_modules']);
        $this->assertSame(['first_broken', 'second_broken'], array_column($missingReleaseIssues, 'module'));
    }

    public function test_health_command_supports_json_and_reports_all_issues(): void
    {
        $this->createModule('first_broken');
        $this->createModule('second_broken');

        $this->assertSame(1, Artisan::call('system:module-health', ['--json' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('"ok":false', $output);
        $this->assertStringContainsString('first_broken', $output);
        $this->assertStringContainsString('second_broken', $output);
    }

    public function test_retention_never_deletes_active_pending_approved_or_rollback_release(): void
    {
        $active = $this->createRelease('1.0.0', 'active', 50);
        $rollback = $this->createRelease('0.9.0', 'superseded', 60, now()->subDays(40));
        $old = $this->createRelease('0.8.0', 'superseded', 70, now()->subDays(80));
        $pending = $this->createRelease('1.1.0', 'pending_review', 20);
        $approved = $this->createRelease('1.2.0', 'approved', 20);
        $this->createModule('release_module', $active->id, $pending->id);

        $result = app(ModuleRetentionService::class)->prune(now()->subDays(30), 100);

        $this->assertSame(1, $result['deleted']['releases']);
        $this->assertFalse(SystemModuleRelease::query()->whereKey($old->id)->exists());
        foreach ([$active, $rollback, $pending, $approved] as $protected) {
            $this->assertTrue(SystemModuleRelease::query()->whereKey($protected->id)->exists());
        }
    }

    public function test_retention_command_prunes_only_completed_old_operations_as_json(): void
    {
        $old = $this->createOperation('succeeded', now()->subDays(40));
        $running = $this->createOperation('running', now()->subDays(40));
        $recent = $this->createOperation('failed', now()->subDays(2));

        $this->artisan('system:module-retention:prune', [
            '--days' => 30,
            '--limit' => 100,
            '--json' => true,
        ])->expectsOutputToContain('"operations":1')->assertExitCode(0);

        $this->assertFalse(SystemModuleOperation::query()->whereKey($old->id)->exists());
        $this->assertTrue(SystemModuleOperation::query()->whereKey($running->id)->exists());
        $this->assertTrue(SystemModuleOperation::query()->whereKey($recent->id)->exists());
    }

    public function test_retention_keeps_release_record_when_artifact_cleanup_is_unsafe(): void
    {
        $release = $this->createRelease('0.7.0', 'rejected', 80);
        $release->forceFill(['artifact_path' => base_path('composer.json')])->save();

        $result = app(ModuleRetentionService::class)->prune(now()->subDays(30), 100);

        $this->assertSame(0, $result['deleted']['releases']);
        $this->assertSame(1, $result['artifact_failures']);
        $this->assertTrue(SystemModuleRelease::query()->whereKey($release->id)->exists());
    }

    public function test_retention_prunes_only_terminal_old_operational_history(): void
    {
        $userId = DB::table('user_account')->insertGetId([
            'email' => 'retention@example.test',
            'password' => 'secret123',
            'nickname' => 'Retention',
            'status' => 'active',
            'source_module' => 'core',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $oldSessionId = DB::table('user_api_sessions')->insertGetId([
            'user_id' => $userId,
            'module' => 'history_module',
            'device_id' => 'old-device',
            'device_name' => 'Old Device',
            'revoked_at' => now()->subDays(40),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(40),
        ]);
        $activeSessionId = DB::table('user_api_sessions')->insertGetId([
            'user_id' => $userId,
            'module' => 'history_module',
            'device_id' => 'active-device',
            'device_name' => 'Active Device',
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);
        $cascadedTokenId = DB::table('user_api_refresh_tokens')->insertGetId([
            'session_id' => $oldSessionId,
            'token_hash' => hash('sha256', 'cascaded'),
            'expires_at' => now()->addDays(30),
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);
        $expiredTokenId = DB::table('user_api_refresh_tokens')->insertGetId([
            'session_id' => $activeSessionId,
            'token_hash' => hash('sha256', 'expired'),
            'expires_at' => now()->subDays(40),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(40),
        ]);
        $activeTokenId = DB::table('user_api_refresh_tokens')->insertGetId([
            'session_id' => $activeSessionId,
            'token_hash' => hash('sha256', 'active'),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldRequestId = $this->createApiRequest($userId, 'completed', now()->subDays(40));
        $runningRequestId = $this->createApiRequest($userId, 'processing', null);
        $recentRequestId = $this->createApiRequest($userId, 'failed', now()->subDays(2));
        $automatedLogId = $this->createModuleLog(null, 'success');
        $adminLogId = $this->createModuleLog(7, 'success');
        $failedLogId = $this->createModuleLog(null, 'failed');
        $sentNotificationId = $this->createNotification('sent');
        $pendingNotificationId = $this->createNotification('pending');

        $result = app(ModuleRetentionService::class)->prune(now()->subDays(30), 100);

        $this->assertSame(1, $result['deleted']['module_api_requests']);
        $this->assertSame(2, $result['deleted']['refresh_tokens']);
        $this->assertSame(1, $result['deleted']['api_sessions']);
        $this->assertSame(1, $result['deleted']['module_logs']);
        $this->assertSame(1, $result['deleted']['notifications']);
        foreach ([
            ['module_api_request', $oldRequestId],
            ['user_api_sessions', $oldSessionId],
            ['user_api_refresh_tokens', $cascadedTokenId],
            ['user_api_refresh_tokens', $expiredTokenId],
            ['system_module_log', $automatedLogId],
            ['user_notification_outbox', $sentNotificationId],
        ] as [$table, $id]) {
            $this->assertFalse(DB::table($table)->where('id', $id)->exists(), "Expected {$table}:{$id} to be pruned.");
        }
        foreach ([
            ['module_api_request', $runningRequestId],
            ['module_api_request', $recentRequestId],
            ['user_api_sessions', $activeSessionId],
            ['user_api_refresh_tokens', $activeTokenId],
            ['system_module_log', $adminLogId],
            ['system_module_log', $failedLogId],
            ['user_notification_outbox', $pendingNotificationId],
        ] as [$table, $id]) {
            $this->assertTrue(DB::table($table)->where('id', $id)->exists(), "Expected {$table}:{$id} to be retained.");
        }
    }

    public function test_scheduler_registers_all_operational_jobs_without_overlap(): void
    {
        $events = app(Schedule::class)->events();
        $expected = [
            'user:notifications:send',
            'user:balance:reconcile',
            'system:module-health --json',
            'system:module-operations:recover --minutes=15 --json',
            'system:module-retention:prune --days=90 --limit=500 --json',
        ];

        foreach ($expected as $command) {
            $event = collect($events)->first(
                static fn ($event): bool => str_contains((string) $event->command, $command)
            );

            $this->assertNotNull($event, "Missing scheduled command: {$command}");
            $this->assertTrue($event->withoutOverlapping, "Overlapping is enabled for: {$command}");
        }
    }

    private function createModule(
        string $name,
        ?int $activeReleaseId = null,
        ?int $pendingReleaseId = null
    ): SystemModule {
        return SystemModule::query()->create([
            'name' => $name,
            'title' => Str::headline($name),
            'vendor' => 'Tests',
            'version' => '1.0.0',
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => storage_path("framework/{$name}"),
            'namespace' => 'Tests\\Modules\\'.Str::studly($name),
            'admin_prefix' => $name,
            'active_release_id' => $activeReleaseId,
            'pending_release_id' => $pendingReleaseId,
            'config_json' => [],
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function createRelease(
        string $version,
        string $status,
        int $daysOld,
        $activatedAt = null
    ): SystemModuleRelease {
        $release = SystemModuleRelease::query()->create([
            'module' => 'release_module',
            'version' => $version,
            'source_type' => 'zip',
            'trust_level' => 'community',
            'artifact_path' => storage_path('modules/releases/release_module/'.$version),
            'artifact_hash' => hash('sha256', $version),
            'manifest_json' => [],
            'status' => $status,
            'activated_at' => $activatedAt,
        ]);
        $release->forceFill([
            'created_at' => now()->subDays($daysOld),
            'updated_at' => now()->subDays($daysOld),
        ])->save();

        return $release;
    }

    private function createOperation(string $status, $finishedAt): SystemModuleOperation
    {
        return SystemModuleOperation::query()->create([
            'id' => (string) Str::uuid(),
            'module' => 'operations_module',
            'active_key' => $status === 'running' ? 'operations_module' : null,
            'action' => 'test',
            'stage' => $status === 'running' ? 'running' : 'completed',
            'status' => $status,
            'started_at' => $finishedAt,
            'heartbeat_at' => $finishedAt,
            'finished_at' => $status === 'running' ? null : $finishedAt,
        ]);
    }

    private function createApiRequest(int $userId, string $status, $finishedAt): int
    {
        return (int) DB::table('module_api_request')->insertGetId([
            'module' => 'history_module',
            'user_id' => $userId,
            'operation' => 'history.test',
            'request_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', Str::random()),
            'status' => $status,
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt ?? now()->subDays(40),
            'updated_at' => $finishedAt ?? now()->subDays(40),
        ]);
    }

    private function createModuleLog(?int $adminId, string $result): int
    {
        return (int) DB::table('system_module_log')->insertGetId([
            'admin_id' => $adminId,
            'module' => 'history_module',
            'action' => 'history',
            'started_at' => now()->subDays(40)->timestamp,
            'finished_at' => now()->subDays(40)->timestamp,
            'result' => $result,
        ]);
    }

    private function createNotification(string $status): int
    {
        return (int) DB::table('user_notification_outbox')->insertGetId([
            'type' => 'history',
            'channel' => 'mail',
            'recipient' => 'retention@example.test',
            'recipient_mask' => 'r***@example.test',
            'subject' => 'History',
            'payload_json' => '{}',
            'status' => $status,
            'sent_at' => $status === 'sent' ? now()->subDays(40) : null,
            'create_time' => now()->subDays(40)->timestamp,
            'update_time' => now()->subDays(40)->timestamp,
        ]);
    }
}
