<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserPasswordReset;
use App\Models\UserSecurityLog;
use App\User\PasswordResetService;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class UserPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createSystemConfigTable();
    }

    public function test_password_reset_phase_3_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_password_reset'));
        $this->assertTrue(Schema::hasTable('user_security_log'));
        $this->assertTrue(Schema::hasColumns('user_password_reset', [
            'user_id',
            'account_type',
            'account',
            'token_hash',
            'code_hash',
            'expires_at',
            'used_at',
            'request_ip',
            'attempt_count',
            'create_time',
        ]));
        $this->assertTrue(Schema::hasColumns('user_security_log', [
            'user_id',
            'event',
            'ip',
            'user_agent',
            'metadata_json',
            'create_time',
        ]));

        $this->assertSame(0, UserPasswordReset::query()->count());
        $this->assertSame(0, UserSecurityLog::query()->count());
        $this->assertSame('Y-m-d H:i:s', (new UserPasswordReset)->getDateFormat());
        $this->assertSame('Y-m-d H:i:s', (new UserNotificationOutbox)->getDateFormat());
    }

    public function test_request_reset_by_email_creates_hashes_without_plaintext(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        $result = app(PasswordResetService::class)->requestReset([
            'account' => ' RESET@example.com ',
        ], '127.0.0.2');

        $this->assertTrue($result['accepted']);
        $this->assertSame('email', $result['account_type']);
        $this->assertSame('reset@example.com', $result['account']);
        $this->assertSame('email', $result['delivery']['channel']);
        $this->assertArrayNotHasKey('notification_id', $result['delivery']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertArrayNotHasKey('code', $result);

        $row = UserPasswordReset::query()->firstOrFail();
        $outbox = UserNotificationOutbox::query()->firstOrFail();
        $token = $outbox->payload_json['token'];
        $code = $outbox->payload_json['code'];

        $this->assertSame('email', $row->account_type);
        $this->assertSame('reset@example.com', $row->account);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame(hash('sha256', $code), $row->code_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame('127.0.0.2', $row->request_ip);
        $this->assertSame(0, $row->attempt_count);
    }

    public function test_unknown_account_reset_request_is_generic_without_reset_row(): void
    {
        $result = app(PasswordResetService::class)->requestReset([
            'account' => 'missing@example.com',
        ], '127.0.0.2');

        $this->assertSame([
            'accepted' => true,
            'account_type' => 'email',
            'account' => 'missing@example.com',
            'delivery' => [
                'channel' => 'email',
                'recipient_mask' => 'm***@example.com',
            ],
            'expires_in' => 1800,
        ], $result);
        $this->assertSame(0, UserPasswordReset::query()->count());
        $this->assertSame(0, UserNotificationOutbox::query()->count());
    }

    public function test_valid_token_resets_password_marks_used_and_writes_security_log(): void
    {
        $registered = app(UserAuthService::class)->register([
            'mobile' => '13920000001',
            'password' => 'old-password',
        ], '127.0.0.1');
        $this->withSession(['user' => ['id' => $registered['user']['id']]]);
        $user = UserAccount::query()->findOrFail($registered['user']['id']);
        $tokens = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'password-reset-device'],
            '127.0.0.1',
            'Password Reset Test'
        );

        $reset = app(PasswordResetService::class)->requestReset([
            'account' => '13920000001',
        ], '127.0.0.2');
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        $result = app(PasswordResetService::class)->resetPassword([
            'account' => '13920000001',
            'token' => $outbox->payload_json['token'],
            'password' => 'new-password',
        ], '127.0.0.3');

        $this->assertTrue($result['reset']);
        $this->assertNull(session('user'));

        $user = UserAccount::query()->findOrFail($registered['user']['id']);
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull(UserPasswordReset::query()->firstOrFail()->used_at);
        $this->assertNull(PersonalAccessToken::findToken($tokens['access_token']));
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized();
        $this->assertDatabaseHas('user_security_log', [
            'user_id' => $registered['user']['id'],
            'event' => 'password_reset_completed',
            'ip' => '127.0.0.3',
        ]);
    }

    public function test_reset_password_hash_supports_new_login_and_rejects_old_password(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'p9-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        app(PasswordResetService::class)->requestReset([
            'account' => 'p9-reset@example.com',
        ], '127.0.0.2');
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        app(PasswordResetService::class)->resetPassword([
            'account' => 'p9-reset@example.com',
            'token' => $outbox->payload_json['token'],
            'password' => 'new-password',
        ], '127.0.0.3');

        $rawPassword = DB::table('user_account')->where('email', 'p9-reset@example.com')->value('password');

        $this->assertIsString($rawPassword);
        $this->assertNotSame('new-password', $rawPassword);
        $this->assertTrue(Hash::check('new-password', $rawPassword));
        $this->assertFalse(Hash::check('old-password', $rawPassword));

        $login = app(UserAuthService::class)->login([
            'account' => 'p9-reset@example.com',
            'password' => 'new-password',
        ], '127.0.0.4');

        $this->assertSame('p9-reset@example.com', $login['user']['email']);

        try {
            app(UserAuthService::class)->login([
                'account' => 'p9-reset@example.com',
                'password' => 'old-password',
            ], '127.0.0.4');

            $this->fail('Expected old password to fail after reset.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号或密码错误。', $exception->getMessage());
        }
    }

    public function test_valid_code_resets_password(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'code-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        $reset = app(PasswordResetService::class)->requestReset([
            'account' => 'code-reset@example.com',
        ], '127.0.0.2');
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        app(PasswordResetService::class)->resetPassword([
            'account' => 'code-reset@example.com',
            'code' => $outbox->payload_json['code'],
            'password' => 'new-password',
        ], '127.0.0.3');

        $user = UserAccount::query()->where('email', 'code-reset@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_expired_used_and_wrong_reset_credentials_are_rejected(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'edge-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        $service = app(PasswordResetService::class);
        $reset = $service->requestReset([
            'account' => 'edge-reset@example.com',
        ], '127.0.0.2');
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        try {
            $service->resetPassword([
                'account' => 'edge-reset@example.com',
                'token' => 'wrong-token',
                'password' => 'new-password',
            ], '127.0.0.3');

            $this->fail('Expected wrong token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('重置凭证无效。', $exception->getMessage());
        }

        $this->assertSame(1, UserPasswordReset::query()->firstOrFail()->attempt_count);

        UserPasswordReset::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        try {
            $service->resetPassword([
                'account' => 'edge-reset@example.com',
                'token' => $outbox->payload_json['token'],
                'password' => 'new-password',
            ], '127.0.0.3');

            $this->fail('Expected expired token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('重置凭证已过期。', $exception->getMessage());
        }

        UserPasswordReset::query()->update([
            'expires_at' => Carbon::now()->addMinutes(10),
            'used_at' => Carbon::now(),
        ]);

        try {
            $service->resetPassword([
                'account' => 'edge-reset@example.com',
                'token' => $outbox->payload_json['token'],
                'password' => 'new-password',
            ], '127.0.0.3');

            $this->fail('Expected used token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('重置凭证已使用。', $exception->getMessage());
        }
    }

    public function test_reset_token_is_blocked_after_too_many_wrong_attempts(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'reset-limit@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $service = app(PasswordResetService::class);
        $service->requestReset([
            'account' => 'reset-limit@example.com',
        ], '127.0.0.1');
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->resetPassword([
                    'account' => 'reset-limit@example.com',
                    'token' => 'bad-secret',
                    'password' => 'new-secret123',
                ], '127.0.0.1');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('重置凭证无效。', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('重置尝试次数过多，请重新申请。');

        $service->resetPassword([
            'account' => 'reset-limit@example.com',
            'token' => $outbox->payload_json['token'],
            'password' => 'new-secret123',
        ], '127.0.0.1');
    }

    public function test_password_reset_endpoints_complete_flow(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'endpoint-reset@example.com',
            'password' => 'old-password',
        ], '127.0.0.1');

        $forgot = $this->postJson('/user/password/forgot', [
            'account' => 'endpoint-reset@example.com',
        ]);

        $forgot->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.account_type', 'email')
            ->assertJsonPath('data.delivery.channel', 'email');
        $this->assertArrayNotHasKey('token', $forgot->json('data'));
        $this->assertArrayNotHasKey('code', $forgot->json('data'));
        $outbox = UserNotificationOutbox::query()->firstOrFail();

        $reset = $this->postJson('/user/password/reset', [
            'account' => 'endpoint-reset@example.com',
            'token' => $outbox->payload_json['token'],
            'password' => 'new-password',
        ]);

        $reset->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.reset', true);

        $login = app(UserAuthService::class)->login([
            'account' => 'endpoint-reset@example.com',
            'password' => 'new-password',
        ], '127.0.0.4');

        $this->assertSame('endpoint-reset@example.com', $login['user']['email']);
    }

    public function test_password_reset_endpoints_return_error_for_bad_payload(): void
    {
        $forgot = $this->postJson('/user/password/forgot', []);
        $forgot->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '账号不能为空。');

        $reset = $this->postJson('/user/password/reset', [
            'account' => 'missing@example.com',
            'password' => '12345',
        ]);
        $reset->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '密码至少需要 6 位。');

        $missingCredential = $this->postJson('/user/password/reset', [
            'account' => 'missing@example.com',
            'password' => 'secret123',
        ]);
        $missingCredential->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请填写重置凭证。');
    }

    public function test_password_reset_routes_use_install_guard_and_throttle(): void
    {
        foreach (['/user/password/forgot', '/user/password/reset'] as $path) {
            $route = Route::getRoutes()->match(Request::create($path, 'POST'));
            $middleware = $route->gatherMiddleware();

            $this->assertContains(CheckInstall::class, $middleware);
            $this->assertTrue(
                collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'throttle:')),
                "{$path} route must be rate limited.",
            );
        }
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
        ]);
    }
}
