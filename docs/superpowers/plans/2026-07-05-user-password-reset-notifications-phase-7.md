# User Password Reset Notifications Phase 7 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the password-reset production loop by sending reset token/code through an auditable notification outbox instead of returning secrets from the public API.

**Architecture:** Keep reset-token creation in `PasswordResetService`, move delivery concerns into `PasswordResetNotificationService`, and persist delivery attempts in an encrypted `user_notification_outbox` table. Dispatch is explicit and retryable through `NotificationOutboxDispatcher` plus an artisan command; email uses Laravel Mail, while SMS starts with a safe log driver and can later be swapped for a real provider without changing reset flow.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent encrypted casts, Laravel Mail fake/array transport, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- `user_notification_outbox` table and model.
- Password-reset notification queueing for email and mobile accounts.
- API response no longer returns reset token or code.
- Outbox payload is encrypted at rest and contains the short-lived token/code only for delivery.
- Notification dispatch service with email sending and SMS log-driver handling.
- Artisan command `user:notifications:send`.
- Focused tests, full-suite verification, and review checkpoint commit.

Excluded:
- Real SMS vendor integration.
- HTML email templates.
- Queue worker integration.
- User-facing frontend pages.
- Purging old notification rows. This can be added in a later retention phase.

---

## File Structure

- Create `database/migrations/2026_07_05_000007_create_user_notification_outbox_phase_7_tables.php`
- Create `app/Models/UserNotificationOutbox.php`
- Create `app/User/PasswordResetNotificationService.php`
- Create `app/User/NotificationOutboxDispatcher.php`
- Modify `app/User/PasswordResetService.php`
- Modify `routes/console.php`
- Modify `tests/Feature/User/UserPasswordResetTest.php`
- Create `tests/Feature/User/UserPasswordResetNotificationTest.php`

---

## Task 1: Notification Outbox Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000007_create_user_notification_outbox_phase_7_tables.php`
- Create: `app/Models/UserNotificationOutbox.php`
- Test: `tests/Feature/User/UserPasswordResetNotificationTest.php`

- [ ] **Step 1: Write failing persistence tests**

Add this test file:

```php
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
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter notification_outbox_table
```

Expected: FAIL because `user_notification_outbox` and `UserNotificationOutbox` do not exist.

- [ ] **Step 3: Implement migration and model**

Create `database/migrations/2026_07_05_000007_create_user_notification_outbox_phase_7_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type', 80)->index();
            $table->string('channel', 32)->index();
            $table->string('recipient', 180);
            $table->string('recipient_mask', 180)->default('');
            $table->string('subject', 180)->default('');
            $table->longText('payload_json');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->integer('create_time')->default(0)->index();
            $table->integer('update_time')->default(0);
            $table->index(['type', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_outbox');
    }
};
```

Create `app/Models/UserNotificationOutbox.php`:

```php
<?php

namespace App\Models;

final class UserNotificationOutbox extends BaseModel
{
    protected $table = 'user_notification_outbox';
    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'payload_json' => 'encrypted:array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
```

- [ ] **Step 4: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter notification_outbox_table
git add database/migrations/2026_07_05_000007_create_user_notification_outbox_phase_7_tables.php app/Models/UserNotificationOutbox.php tests/Feature/User/UserPasswordResetNotificationTest.php
git commit -m "feat: add user notification outbox"
```

## Task 2: Queue Password Reset Notifications

**Files:**
- Create: `app/User/PasswordResetNotificationService.php`
- Modify: `app/User/PasswordResetService.php`
- Modify: `tests/Feature/User/UserPasswordResetTest.php`
- Modify: `tests/Feature/User/UserPasswordResetNotificationTest.php`

- [ ] **Step 1: Add failing queueing tests**

Append to `tests/Feature/User/UserPasswordResetNotificationTest.php`:

```php
use App\Models\UserAccount;
use App\Models\UserPasswordReset;
use App\User\PasswordResetService;
use App\User\UserAuthService;

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
```

Update `tests/Feature/User/UserPasswordResetTest.php`:

```php
// Replace direct response secret assertions with outbox lookup:
$outbox = \App\Models\UserNotificationOutbox::query()->firstOrFail();
$this->assertArrayNotHasKey('token', $forgot->json('data'));
$this->assertArrayNotHasKey('code', $forgot->json('data'));
$this->assertNotEmpty($outbox->payload_json['token']);
$this->assertNotEmpty($outbox->payload_json['code']);

// Use this in reset calls:
'token' => $outbox->payload_json['token'],
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "queues"
```

Expected: FAIL because `PasswordResetNotificationService` does not exist and `PasswordResetService` still returns token/code.

- [ ] **Step 3: Implement notification queueing**

Create `app/User/PasswordResetNotificationService.php`:

```php
<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserPasswordReset;

final class PasswordResetNotificationService
{
    public function queue(UserAccount $user, UserPasswordReset $reset, string $token, string $code): array
    {
        $channel = $reset->account_type === 'email' ? 'email' : 'sms';
        $recipient = (string) $reset->account;
        $mask = $channel === 'email' ? $this->maskEmail($recipient) : $this->maskMobile($recipient);
        $subject = $channel === 'email' ? 'Reset your EasyAdmin8 password' : 'EasyAdmin8 password reset code';
        $now = time();

        $outbox = UserNotificationOutbox::query()->create([
            'user_id' => $user->id,
            'type' => 'password_reset',
            'channel' => $channel,
            'recipient' => $recipient,
            'recipient_mask' => $mask,
            'subject' => $subject,
            'payload_json' => [
                'password_reset_id' => (int) $reset->id,
                'account_type' => $reset->account_type,
                'token' => $token,
                'code' => $code,
                'expires_at' => $reset->expires_at?->toISOString(),
            ],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now(),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return [
            'notification_id' => (int) $outbox->id,
            'channel' => $channel,
            'recipient_mask' => $mask,
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = $local === '' ? '*' : substr($local, 0, 1);

        return $prefix.'***@'.$domain;
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 7) {
            return str_repeat('*', strlen($mobile));
        }

        return substr($mobile, 0, 3).'****'.substr($mobile, -4);
    }
}
```

Modify `app/User/PasswordResetService.php` constructor:

```php
public function __construct(
    private readonly UserSecurityLogService $securityLogs,
    private readonly PasswordResetNotificationService $notifications
) {
}
```

Modify `requestReset` after creating `$reset`:

```php
$reset = UserPasswordReset::query()->create([
    // existing fields
]);
```

Then replace the return payload:

```php
$delivery = $this->notifications->queue($user, $reset, $token, $code);

return [
    'accepted' => true,
    'account_type' => $accountType,
    'account' => $account,
    'delivery' => $delivery,
    'expires_in' => 1800,
];
```

- [ ] **Step 4: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "queues"
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php
git add app/User/PasswordResetNotificationService.php app/User/PasswordResetService.php tests/Feature/User/UserPasswordResetTest.php tests/Feature/User/UserPasswordResetNotificationTest.php
git commit -m "feat: queue password reset notifications"
```

## Task 3: Dispatch Pending Notifications

**Files:**
- Create: `app/User/NotificationOutboxDispatcher.php`
- Modify: `routes/console.php`
- Modify: `tests/Feature/User/UserPasswordResetNotificationTest.php`

- [ ] **Step 1: Add failing dispatch tests**

Append to `tests/Feature/User/UserPasswordResetNotificationTest.php`:

```php
use App\User\NotificationOutboxDispatcher;
use Illuminate\Support\Facades\Mail;

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
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "dispatcher|command"
```

Expected: FAIL because `NotificationOutboxDispatcher` and `user:notifications:send` do not exist.

- [ ] **Step 3: Implement dispatcher and command**

Create `app/User/NotificationOutboxDispatcher.php`:

```php
<?php

namespace App\User;

use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class NotificationOutboxDispatcher
{
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sent = 0;
        $failed = 0;

        $rows = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                $this->sendOne($row);
                $row->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'last_error' => null,
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'update_time' => time(),
                ])->save();
                $sent++;
            } catch (Throwable $exception) {
                $row->forceFill([
                    'status' => 'failed',
                    'last_error' => substr($exception->getMessage(), 0, 1000),
                    'attempt_count' => ((int) $row->attempt_count) + 1,
                    'available_at' => now()->addMinutes(5),
                    'update_time' => time(),
                ])->save();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function sendOne(UserNotificationOutbox $row): void
    {
        $payload = $row->payload_json;
        $message = $this->passwordResetMessage($payload);

        if ($row->channel === 'email') {
            Mail::raw($message, function ($mail) use ($row): void {
                $mail->to($row->recipient)->subject($row->subject);
            });

            return;
        }

        if ($row->channel === 'sms') {
            Log::info('Password reset SMS notification queued through log driver.', [
                'notification_id' => $row->id,
                'recipient_mask' => $row->recipient_mask,
                'code' => $payload['code'] ?? null,
            ]);

            return;
        }

        throw new \InvalidArgumentException('Unsupported notification channel.');
    }

    private function passwordResetMessage(array $payload): string
    {
        return implode("\n", [
            'Your EasyAdmin8 password reset request was received.',
            'Reset token: '.($payload['token'] ?? ''),
            'Reset code: '.($payload['code'] ?? ''),
            'This request expires at: '.($payload['expires_at'] ?? ''),
        ]);
    }
}
```

Append to `routes/console.php`:

```php
Artisan::command('user:notifications:send {--limit=50}', function (): int {
    $result = app(\App\User\NotificationOutboxDispatcher::class)->sendPending((int) $this->option('limit'));
    $this->info('sent='.$result['sent'].' failed='.$result['failed']);

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Send pending user notification outbox rows');
```

- [ ] **Step 4: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "dispatcher|command"
git add app/User/NotificationOutboxDispatcher.php routes/console.php tests/Feature/User/UserPasswordResetNotificationTest.php
git commit -m "feat: dispatch password reset notifications"
```

## Task 4: Review and Full Verification

- [ ] **Step 1: Focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAuthTest.php
```

- [ ] **Step 2: Full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Models\UserNotificationOutbox.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\PasswordResetNotificationService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\NotificationOutboxDispatcher.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\PasswordResetService.php
E:\code\user\.tools\php-8.3.32\php.exe -l routes\console.php
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- public forgot-password API does not return `token` or `code`;
- `user_password_reset` still stores only `token_hash` and `code_hash`;
- notification outbox raw database payload does not contain plaintext token/code;
- model encrypted cast can decrypt payload for dispatch;
- unknown-account forgot-password requests still return generic accepted response and do not create reset/outbox rows;
- email dispatch uses Laravel Mail and is covered by `Mail::fake`;
- SMS dispatch does not claim real external delivery and is explicitly log-driver only;
- failed dispatch increments attempts and stores a bounded error;
- command output reports sent and failed counts.

- [ ] **Step 5: Review commit**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review password reset notifications phase 7"
```

## Plan Self-Review

- Spec coverage: This plan closes the highest-priority production gap for password reset by removing public secret return, adding encrypted notification persistence, and adding a retryable dispatcher.
- Deferred-wording scan: No task uses deferred wording or vague "add tests" wording; each task lists exact files, commands, expected failures, and implementation code.
- Type consistency: `UserNotificationOutbox`, `PasswordResetNotificationService`, `NotificationOutboxDispatcher`, `payload_json`, `recipient_mask`, and `user:notifications:send` are named consistently across tasks.
- Scope guard: Real SMS provider, frontend pages, queue worker scheduling, and retention cleanup remain intentionally outside this first P7 slice.
