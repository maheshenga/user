<?php

namespace Tests\Feature\User;

use App\Models\UserNotificationOutbox;
use App\Models\UserPasswordReset;
use App\User\NotificationOutboxDispatcher;
use App\User\PasswordResetService;
use App\User\UserAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    public function test_password_reset_request_queues_email_notification_without_returning_secret(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'notify-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        $result = app(PasswordResetService::class)->requestReset([
            'account' => 'notify-reset@example.com',
        ], '127.0.0.2');

        $this->assertTrue($result['accepted']);
        $this->assertArrayHasKey('delivery', $result);
        $this->assertSame('email', $result['delivery']['channel']);
        $this->assertSame('n***@example.com', $result['delivery']['recipient_mask']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertArrayNotHasKey('code', $result);

        $outbox = UserNotificationOutbox::query()->firstOrFail();
        $this->assertSame('password_reset', $outbox->type);
        $this->assertSame('email', $outbox->channel);
        $this->assertSame('notify-reset@example.com', $outbox->recipient);
        $this->assertSame('pending', $outbox->status);
        $this->assertSame(UserPasswordReset::query()->firstOrFail()->id, $outbox->payload_json['password_reset_id']);
        $this->assertNotEmpty($outbox->payload_json['token']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $outbox->payload_json['code']);

        $rawPayload = DB::table('user_notification_outbox')->where('id', $outbox->id)->value('payload_json');
        $this->assertStringNotContainsString($outbox->payload_json['token'], (string) $rawPayload);
        $this->assertStringNotContainsString($outbox->payload_json['code'], (string) $rawPayload);
    }

    public function test_password_reset_request_queues_mobile_notification_with_masked_recipient(): void
    {
        app(UserAuthService::class)->register([
            'mobile' => '13920009999',
            'password' => 'old-password',
        ], '127.0.0.1');

        $result = app(PasswordResetService::class)->requestReset([
            'account' => '13920009999',
        ], '127.0.0.2');

        $this->assertArrayHasKey('delivery', $result);
        $this->assertSame('sms', $result['delivery']['channel']);
        $this->assertSame('139****9999', $result['delivery']['recipient_mask']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertArrayNotHasKey('code', $result);
        $this->assertDatabaseHas('user_notification_outbox', [
            'type' => 'password_reset',
            'channel' => 'sms',
            'recipient' => '13920009999',
            'recipient_mask' => '139****9999',
            'status' => 'pending',
        ]);
    }

    public function test_dispatcher_sends_pending_email_and_marks_it_sent(): void
    {
        Mail::fake();
        app(UserAuthService::class)->register([
            'email' => 'dispatch-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        app(PasswordResetService::class)->requestReset([
            'account' => 'dispatch-reset@example.com',
        ], '127.0.0.2');

        $result = app(NotificationOutboxDispatcher::class)->sendPending(10);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $outbox = UserNotificationOutbox::query()->firstOrFail();
        $this->assertSame('sent', $outbox->status);
        $this->assertNotNull($outbox->sent_at);
        Mail::assertSentCount(1);
    }

    public function test_dispatcher_marks_sms_log_driver_sent_without_external_provider(): void
    {
        app(UserAuthService::class)->register([
            'mobile' => '13920008888',
            'password' => 'old-password',
        ], '127.0.0.1');

        app(PasswordResetService::class)->requestReset([
            'account' => '13920008888',
        ], '127.0.0.2');

        $result = app(NotificationOutboxDispatcher::class)->sendPending(10);

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('user_notification_outbox', [
            'channel' => 'sms',
            'status' => 'sent',
        ]);
    }

    public function test_notification_send_command_dispatches_pending_rows(): void
    {
        Mail::fake();
        app(UserAuthService::class)->register([
            'email' => 'command-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');
        app(PasswordResetService::class)->requestReset(['account' => 'command-reset@example.com'], '127.0.0.2');

        $this->artisan('user:notifications:send', ['--limit' => 10])
            ->expectsOutputToContain('sent=1')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_dispatch_failure_keeps_row_pending_for_retry_with_bounded_error(): void
    {
        UserNotificationOutbox::query()->create([
            'user_id' => 10,
            'type' => 'password_reset',
            'channel' => 'unsupported',
            'recipient' => 'retry@example.com',
            'recipient_mask' => 'r***@example.com',
            'subject' => 'Retry me',
            'payload_json' => ['token' => 'retry-token', 'code' => '654321'],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $result = app(NotificationOutboxDispatcher::class)->sendPending(10);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $outbox = UserNotificationOutbox::query()->firstOrFail();
        $this->assertSame('pending', $outbox->status);
        $this->assertSame(1, (int) $outbox->attempt_count);
        $this->assertNotEmpty($outbox->last_error);
        $this->assertLessThanOrEqual(1000, strlen((string) $outbox->last_error));
        $this->assertTrue($outbox->available_at->isFuture());
    }
}
