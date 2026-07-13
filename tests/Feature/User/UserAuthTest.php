<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use App\Models\UserProfile;
use App\Http\Middleware\CheckInstall;
use App\User\UserAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createSystemConfigTable();
    }

    public function test_user_account_phase_1_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_account'));
        $this->assertTrue(Schema::hasTable('user_profile'));
        $this->assertTrue(Schema::hasTable('user_login_log'));

        $this->assertTrue(Schema::hasColumns('user_account', [
            'mobile',
            'email',
            'password',
            'status',
            'source_module',
            'available_balance',
            'frozen_balance',
            'vip_level',
            'vip_expires_at',
            'delete_time',
        ]));

        $this->assertSame(0, UserAccount::query()->count());
        $this->assertSame(0, UserProfile::query()->count());
        $this->assertSame(0, UserLoginLog::query()->count());
    }

    public function test_user_account_phase_1_models_persist_with_expected_casts(): void
    {
        $lastLoginAt = Carbon::create(2026, 7, 5, 10, 20, 30);

        $account = UserAccount::query()->create([
            'mobile' => '13800138000',
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'last_login_at' => $lastLoginAt,
            'available_balance' => 12.3,
            'frozen_balance' => 4,
        ]);

        $account->refresh();

        $this->assertSame(1, UserAccount::query()->count());
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->assertSame('12.30', $account->available_balance);
        $this->assertSame('4.00', $account->frozen_balance);

        $rawLastLoginAt = DB::table('user_account')->where('id', $account->id)->value('last_login_at');
        $rawPassword = DB::table('user_account')->where('id', $account->id)->value('password');

        $this->assertNotSame('secret-password', $rawPassword);
        $this->assertTrue(Hash::check('secret-password', $rawPassword));
        $this->assertSame('2026-07-05 10:20:30', $rawLastLoginAt);
        $this->assertFalse(ctype_digit((string) $rawLastLoginAt));

        $profile = UserProfile::query()->create([
            'user_id' => $account->id,
            'metadata_json' => [
                'source' => 'feature-test',
                'flags' => ['phase_1'],
            ],
        ]);

        $profile->refresh();

        $this->assertSame([
            'source' => 'feature-test',
            'flags' => ['phase_1'],
        ], $profile->metadata_json);

        UserLoginLog::query()->create([
            'user_id' => $account->id,
            'account' => 'user@example.com',
            'login_type' => 'password',
            'ip' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'result' => 'success',
        ]);

        $this->assertSame(1, UserLoginLog::query()->count());

        $account->delete();

        $rawDeleteTime = DB::table('user_account')->where('id', $account->id)->value('delete_time');

        $this->assertIsInt($rawDeleteTime);
        $this->assertGreaterThan(0, $rawDeleteTime);
    }

    public function test_user_can_register_with_mobile_only(): void
    {
        $result = app(UserAuthService::class)->register([
            'mobile' => '13800000001',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->assertSame('13800000001', $result['user']['mobile']);
        $this->assertNull($result['user']['email']);
        $this->assertSame('active', $result['user']['status']);
        $this->assertSame('core', $result['user']['source_module']);
        $this->assertArrayNotHasKey('password', $result['user']);

        $this->assertDatabaseHas('user_account', [
            'mobile' => '13800000001',
            'email' => null,
            'status' => 'active',
            'source_module' => 'core',
            'register_ip' => '127.0.0.1',
        ]);

        $rawPassword = DB::table('user_account')->where('mobile', '13800000001')->value('password');

        $this->assertNotSame('secret123', $rawPassword);
        $this->assertTrue(Hash::check('secret123', $rawPassword));
    }

    public function test_registered_password_hash_supports_login_and_rejects_wrong_password(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'email' => 'p9-hash@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $rawPassword = DB::table('user_account')->where('email', 'p9-hash@example.com')->value('password');

        $this->assertIsString($rawPassword);
        $this->assertNotSame('secret123', $rawPassword);
        $this->assertTrue(Hash::check('secret123', $rawPassword));

        $login = $service->login([
            'account' => 'p9-hash@example.com',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertSame('p9-hash@example.com', $login['user']['email']);

        try {
            $service->login([
                'account' => 'p9-hash@example.com',
                'password' => 'wrong-password',
            ], '127.0.0.2');

            $this->fail('Expected wrong password to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号或密码错误。', $exception->getMessage());
        }
    }

    public function test_user_can_register_with_email_only(): void
    {
        $result = app(UserAuthService::class)->register([
            'email' => 'person@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->assertNull($result['user']['mobile']);
        $this->assertSame('person@example.com', $result['user']['email']);
        $this->assertSame('active', $result['user']['status']);
        $this->assertArrayNotHasKey('password', $result['user']);

        $this->assertDatabaseHas('user_account', [
            'mobile' => null,
            'email' => 'person@example.com',
            'status' => 'active',
            'register_ip' => '127.0.0.1',
        ]);
    }

    public function test_user_registration_tracks_source_module(): void
    {
        $result = app(UserAuthService::class)->register([
            'email' => 'module-register@example.com',
            'password' => 'secret123',
            'source_module' => 'vip_center',
        ], '127.0.0.1');

        $this->assertSame('vip_center', $result['user']['source_module']);

        $this->assertDatabaseHas('user_account', [
            'email' => 'module-register@example.com',
            'source_module' => 'vip_center',
        ]);
    }

    public function test_user_can_login_with_mobile_and_logout(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'mobile' => '13800000003',
            'password' => 'secret123',
        ], '127.0.0.1');

        $result = $service->login([
            'account' => '13800000003',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertSame('13800000003', $result['user']['mobile']);
        $this->assertSame($result['user']['id'], session('user.id'));
        $this->assertDatabaseHas('user_login_log', [
            'user_id' => $result['user']['id'],
            'account' => '13800000003',
            'login_type' => 'mobile',
            'result' => 'success',
        ]);

        $service->logout();

        $this->assertNull(session('user'));
    }

    public function test_user_can_login_with_email(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'email' => 'login@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $result = $service->login([
            'account' => 'LOGIN@example.com',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertSame('login@example.com', $result['user']['email']);
        $this->assertDatabaseHas('user_login_log', [
            'user_id' => $result['user']['id'],
            'account' => 'login@example.com',
            'login_type' => 'email',
            'result' => 'success',
        ]);
    }

    public function test_register_endpoint_returns_user_payload(): void
    {
        $response = $this->postJson('/user/register', [
            'mobile' => '13800000005',
            'password' => 'secret123',
            'source_module' => 'invite_portal',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.mobile', '13800000005')
            ->assertJsonPath('data.user.source_module', 'invite_portal');

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    public function test_login_endpoint_sets_user_session(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'endpoint@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $response = $this->postJson('/user/login', [
            'account' => 'endpoint@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'endpoint@example.com')
            ->assertSessionHas('user.email', 'endpoint@example.com');

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('password', session('user'));
    }

    public function test_logout_endpoint_clears_user_session(): void
    {
        $response = $this
            ->withSession(['user' => ['id' => 10, 'email' => 'old@example.com']])
            ->postJson('/user/logout');

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertSessionMissing('user');
    }

    public function test_register_endpoint_returns_error_for_invalid_payload(): void
    {
        $response = $this->postJson('/user/register', [
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请填写手机号或邮箱。');

        $this->postJson('/user/register', [
            'mobile' => '13800000012',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '密码不能为空。');
    }

    public function test_user_api_messages_are_chinese(): void
    {
        $this->getJson('/user/session')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请先登录。');

        $this->getJson('/user/vip')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请先登录。');

        $this->postJson('/user/register', [
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '请填写手机号或邮箱。');
    }

    public function test_user_auth_endpoint_routes_use_install_guard_and_throttle(): void
    {
        foreach (['/user/register', '/user/login', '/user/logout'] as $path) {
            $route = Route::getRoutes()->match(Request::create($path, 'POST'));
            $middleware = $route->gatherMiddleware();

            $this->assertContains(CheckInstall::class, $middleware);
            $this->assertTrue(
                collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'throttle:')),
                "{$path} route must be rate limited.",
            );
        }
    }

    public function test_register_endpoint_returns_error_for_duplicate_mobile(): void
    {
        app(UserAuthService::class)->register([
            'mobile' => '13800000011',
            'password' => 'secret123',
        ], '127.0.0.1');

        $response = $this->postJson('/user/register', [
            'mobile' => '13800000011',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_login_endpoint_returns_error_for_bad_credentials(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'bad-login@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $response = $this->postJson('/user/login', [
            'account' => 'bad-login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 0);

        $this->postJson('/user/login', [
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '账号不能为空。');

        $this->postJson('/user/login', [
            'account' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '密码不能为空。');
    }

    public function test_login_requires_account_and_password_without_logging(): void
    {
        $service = app(UserAuthService::class);

        foreach ([['password' => 'secret123'], ['account' => '13800000008']] as $payload) {
            try {
                $service->login($payload, '127.0.0.2');

                $this->fail('Expected missing login credentials to fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('请填写账号和密码。', $exception->getMessage());
            }
        }

        $this->assertSame(0, UserLoginLog::query()->count());
    }

    public function test_nonexistent_account_login_logs_normalized_failure(): void
    {
        $service = app(UserAuthService::class);

        try {
            $service->login([
                'account' => ' MISSING@EXAMPLE.COM ',
                'password' => 'secret123',
            ], '127.0.0.2');

            $this->fail('Expected nonexistent account login to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号或密码错误。', $exception->getMessage());
        }

        $this->assertDatabaseHas('user_login_log', [
            'user_id' => null,
            'account' => 'missing@example.com',
            'login_type' => 'email',
            'result' => 'failed',
            'error_message' => '账号或密码错误。',
        ]);
    }

    public function test_login_rejects_wrong_password_and_logs_failure(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'mobile' => '13800000007',
            'password' => 'secret123',
        ], '127.0.0.1');

        try {
            $service->login([
                'account' => '13800000007',
                'password' => 'wrong-password',
            ], '127.0.0.2');

            $this->fail('Expected wrong password login to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号或密码错误。', $exception->getMessage());
        } finally {
            $this->assertDatabaseHas('user_login_log', [
                'user_id' => null,
                'account' => '13800000007',
                'login_type' => 'mobile',
                'result' => 'failed',
                'error_message' => '账号或密码错误。',
            ]);
        }
    }

    public function test_login_locks_account_after_repeated_failures(): void
    {
        $service = app(UserAuthService::class);
        $service->register([
            'email' => 'lock@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->login([
                    'account' => 'lock@example.com',
                    'password' => 'wrong-password',
                ], '127.0.0.9');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('账号或密码错误。', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('登录失败次数过多，请 15 分钟后再试。');

        $service->login([
            'account' => 'lock@example.com',
            'password' => 'secret123',
        ], '127.0.0.9');
    }

    public function test_disabled_user_cannot_login(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'email' => 'disabled@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $user = UserAccount::query()->where('email', 'disabled@example.com')->firstOrFail();
        $user->update(['status' => 'disabled']);

        try {
            $service->login([
                'account' => 'disabled@example.com',
                'password' => 'secret123',
            ], '127.0.0.2');

            $this->fail('Expected disabled user login to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号当前不可登录。', $exception->getMessage());
        } finally {
            $this->assertDatabaseHas('user_login_log', [
                'user_id' => $user->id,
                'account' => 'disabled@example.com',
                'login_type' => 'email',
                'result' => 'failed',
                'error_message' => '账号当前不可登录。',
            ]);
        }
    }

    public function test_frozen_user_cannot_login(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'email' => 'frozen@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $user = UserAccount::query()->where('email', 'frozen@example.com')->firstOrFail();
        $user->update(['status' => 'frozen']);

        try {
            $service->login([
                'account' => 'frozen@example.com',
                'password' => 'secret123',
            ], '127.0.0.2');

            $this->fail('Expected frozen user login to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('账号当前不可登录。', $exception->getMessage());
        } finally {
            $this->assertDatabaseHas('user_login_log', [
                'user_id' => $user->id,
                'account' => 'frozen@example.com',
                'login_type' => 'email',
                'result' => 'failed',
                'error_message' => '账号当前不可登录。',
            ]);
        }
    }

    public function test_successful_login_updates_raw_last_login_fields(): void
    {
        $service = app(UserAuthService::class);
        Carbon::setTestNow(Carbon::create(2026, 7, 5, 11, 22, 33));

        try {
            $service->register([
                'mobile' => '13800000008',
                'password' => 'secret123',
            ], '127.0.0.1');

            $result = $service->login([
                'account' => '13800000008',
                'password' => 'secret123',
            ], '127.0.0.9');

            $rawUser = DB::table('user_account')->where('id', $result['user']['id'])->first();

            $this->assertSame('2026-07-05 11:22:33', $rawUser->last_login_at);
            $this->assertSame('127.0.0.9', $rawUser->last_login_ip);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_session_user_payload_excludes_password(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'mobile' => '13800000009',
            'password' => 'secret123',
        ], '127.0.0.1');

        $service->login([
            'account' => '13800000009',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertArrayNotHasKey('password', session('user'));
    }

    public function test_successful_login_regenerates_session_id(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'mobile' => '13800000012',
            'password' => 'secret123',
        ], '127.0.0.1');

        $previousSessionId = session()->getId();

        $service->login([
            'account' => '13800000012',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertNotSame($previousSessionId, session()->getId());
    }

    public function test_login_log_truncates_user_agent_to_500_characters(): void
    {
        $service = app(UserAuthService::class);
        $userAgent = str_repeat('A', 550);
        request()->headers->set('User-Agent', $userAgent);

        $service->register([
            'mobile' => '13800000010',
            'password' => 'secret123',
        ], '127.0.0.1');

        $service->login([
            'account' => '13800000010',
            'password' => 'secret123',
        ], '127.0.0.2');

        $storedUserAgent = UserLoginLog::query()->value('user_agent');

        $this->assertSame(500, strlen($storedUserAgent));
        $this->assertSame(substr($userAgent, 0, 500), $storedUserAgent);
    }

    public function test_register_requires_mobile_or_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('请填写手机号或邮箱。');

        app(UserAuthService::class)->register([
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    public function test_register_rejects_blank_contact_strings_as_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('请填写手机号或邮箱。');

        app(UserAuthService::class)->register([
            'mobile' => '   ',
            'email' => '   ',
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    public function test_register_rejects_short_password(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('密码至少需要 6 位。');

        app(UserAuthService::class)->register([
            'mobile' => '13800000004',
            'password' => '12345',
        ], '127.0.0.1');
    }

    public function test_register_normalizes_mobile_and_email_before_persisting(): void
    {
        $result = app(UserAuthService::class)->register([
            'mobile' => ' 13800000005 ',
            'email' => ' PERSON3@EXAMPLE.COM ',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->assertSame('13800000005', $result['user']['mobile']);
        $this->assertSame('person3@example.com', $result['user']['email']);

        $this->assertDatabaseHas('user_account', [
            'mobile' => '13800000005',
            'email' => 'person3@example.com',
        ]);
    }

    public function test_register_checks_duplicates_after_normalization(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'email' => 'person4@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('邮箱已存在。');

        $service->register([
            'email' => ' PERSON4@EXAMPLE.COM ',
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    public function test_register_rejects_duplicate_mobile_or_email(): void
    {
        $service = app(UserAuthService::class);

        $service->register([
            'mobile' => '13800000002',
            'email' => 'person2@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        try {
            $service->register([
                'mobile' => '13800000002',
                'email' => 'other@example.com',
                'password' => 'secret123',
            ], '127.0.0.1');

            $this->fail('Expected duplicate mobile registration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('手机号已存在。', $exception->getMessage());
        }

        try {
            $service->register([
                'mobile' => '13800000003',
                'email' => 'person2@example.com',
                'password' => 'secret123',
            ], '127.0.0.1');

            $this->fail('Expected duplicate email registration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('邮箱已存在。', $exception->getMessage());
        }
    }

    public function test_register_translates_racing_mobile_unique_constraint(): void
    {
        $service = app(UserAuthService::class);
        $inserted = false;

        UserAccount::creating(function (UserAccount $account) use (&$inserted): void {
            if ($inserted || $account->mobile !== '13800000006') {
                return;
            }

            $inserted = true;

            DB::table('user_account')->insert([
                'mobile' => '13800000006',
                'email' => null,
                'password' => 'race-password',
                'nickname' => '13800000006',
                'status' => 'active',
                'register_ip' => '127.0.0.2',
                'create_time' => time(),
                'update_time' => time(),
            ]);
        });

        try {
            $service->register([
                'mobile' => '13800000006',
                'password' => 'secret123',
            ], '127.0.0.1');

            $this->fail('Expected racing duplicate mobile registration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('手机号已存在。', $exception->getMessage());
        } finally {
            UserAccount::flushEventListeners();
        }
    }

    public function test_register_translates_racing_email_unique_constraint(): void
    {
        $service = app(UserAuthService::class);
        $inserted = false;

        UserAccount::creating(function (UserAccount $account) use (&$inserted): void {
            if ($inserted || $account->email !== 'race@example.com') {
                return;
            }

            $inserted = true;

            DB::table('user_account')->insert([
                'mobile' => null,
                'email' => 'race@example.com',
                'password' => 'race-password',
                'nickname' => 'race@example.com',
                'status' => 'active',
                'register_ip' => '127.0.0.2',
                'create_time' => time(),
                'update_time' => time(),
            ]);
        });

        try {
            $service->register([
                'email' => 'race@example.com',
                'password' => 'secret123',
            ], '127.0.0.1');

            $this->fail('Expected racing duplicate email registration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('邮箱已存在。', $exception->getMessage());
        } finally {
            UserAccount::flushEventListeners();
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
