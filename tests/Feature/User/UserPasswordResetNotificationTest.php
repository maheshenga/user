<?php

namespace Tests\Feature\User;

use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPasswordResetNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_notification_outbox_table_exists_and_payload_is_encrypted(): void
    {
        $this->assertTrue(Schema::hasTable('user_notification_outbox'));
        $this->assertTrue(Schema::hasColumns('user_notification_outbox', [
            'id',
            'user_id',
            'type',
            'channel',
            'recipient',
            'recipient_mask',
            'subject',
            'payload_json',
            'status',
            'attempt_count',
            'last_error',
            'available_at',
            'sent_at',
            'create_time',
            'update_time',
        ]));

        $outbox = UserNotificationOutbox::query()->create([
            'user_id' => 10,
            'type' => 'password_reset',
            'channel' => 'email',
            'recipient' => 'reset@example.com',
            'recipient_mask' => 'r***@example.com',
            'subject' => 'Reset your password',
            'payload_json' => ['token' => 'secret-token', 'code' => '123456'],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->assertSame('secret-token', $outbox->refresh()->payload_json['token']);
        $rawPayload = DB::table('user_notification_outbox')->where('id', $outbox->id)->value('payload_json');
        $this->assertStringNotContainsString('secret-token', (string) $rawPayload);
        $this->assertStringNotContainsString('123456', (string) $rawPayload);
    }
}
