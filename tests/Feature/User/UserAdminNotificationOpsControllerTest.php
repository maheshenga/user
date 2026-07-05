<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminNotificationOpsControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();

        $this->withoutMiddleware([
            CheckInstall::class,
            CheckAuth::class,
            RateLimiting::class,
            SystemLog::class,
        ]);

        DB::table('system_admin')->updateOrInsert(['id' => 77], ['status' => 1, 'auth_ids' => '']);
        $this->withSession(['admin.id' => 77, 'admin.expire_time' => true]);
    }

    public function test_admin_notification_outbox_index_returns_safe_rows(): void
    {
        $row = $this->createOutbox('pending', 3);

        $response = $this->getJson('/admin/user/notification-outbox/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.recipient_mask', 'o***@example.com');

        $payload = $response->json('data.0');
        $this->assertArrayNotHasKey('payload_json', $payload);
        $this->assertArrayNotHasKey('recipient', $payload);
    }

    public function test_admin_notification_outbox_stats_returns_summary(): void
    {
        $this->createOutbox('sent', 1, now()->subMinutes(10));
        $this->createOutbox('pending', 2, now()->subMinute());
        $this->createOutbox('pending', 2, now()->addMinutes(10));

        $response = $this->getJson('/admin/user/notification-outbox/stats');

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_status.sent', 1)
            ->assertJsonPath('data.by_status.pending', 2)
            ->assertJsonPath('data.retryable_pending', 1)
            ->assertJsonPath('data.delayed_pending', 1);
    }

    public function test_admin_notification_outbox_blocks_unsafe_inherited_actions(): void
    {
        $row = $this->createOutbox('pending', 1);

        foreach ([
            ['postJson', '/admin/user/notification-outbox/add', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/edit', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/delete', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/modify', ['id' => $row->id, 'field' => 'status', 'value' => 'sent']],
            ['getJson', '/admin/user/notification-outbox/recycle', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertOk()->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/notification-outbox/export')->assertForbidden();
        $this->assertDatabaseHas('user_notification_outbox', ['id' => $row->id, 'status' => 'pending']);
    }

    private function createOutbox(string $status, int $attempts, mixed $availableAt = null): UserNotificationOutbox
    {
        $time = $availableAt ?? now();

        return UserNotificationOutbox::query()->create([
            'user_id' => 10,
            'type' => 'password_reset',
            'channel' => 'email',
            'recipient' => 'ops@example.com',
            'recipient_mask' => 'o***@example.com',
            'subject' => 'Ops row',
            'payload_json' => ['token' => 'ops-token', 'code' => '123456'],
            'status' => $status,
            'attempt_count' => $attempts,
            'available_at' => $time,
            'sent_at' => $status === 'sent' ? $time : null,
            'create_time' => $time->timestamp,
            'update_time' => $time->timestamp,
        ]);
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 80)->default('');
                $table->string('name', 120);
                $table->text('value')->nullable();
            });
        }

        DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => '8.0.0'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin8'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'textarea'],
            ['group' => 'site', 'name' => 'iframe_open_top', 'value' => '0'],
        ]);
    }
}
