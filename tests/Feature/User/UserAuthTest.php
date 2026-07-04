<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use App\Models\UserProfile;
use App\User\UserAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->assertArrayNotHasKey('password', $result['user']);

        $this->assertDatabaseHas('user_account', [
            'mobile' => '13800000001',
            'email' => null,
            'status' => 'active',
            'register_ip' => '127.0.0.1',
        ]);

        $rawPassword = DB::table('user_account')->where('mobile', '13800000001')->value('password');

        $this->assertNotSame('secret123', $rawPassword);
        $this->assertTrue(Hash::check('secret123', $rawPassword));
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
            $this->assertSame('Invalid account or password.', $exception->getMessage());
        } finally {
            $this->assertDatabaseHas('user_login_log', [
                'user_id' => null,
                'account' => '13800000007',
                'login_type' => 'mobile',
                'result' => 'failed',
                'error_message' => 'Invalid account or password.',
            ]);
        }
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
            $this->assertSame('User account is not active.', $exception->getMessage());
        } finally {
            $this->assertDatabaseHas('user_login_log', [
                'user_id' => $user->id,
                'account' => 'disabled@example.com',
                'login_type' => 'email',
                'result' => 'failed',
                'error_message' => 'User account is not active.',
            ]);
        }
    }

    public function test_register_requires_mobile_or_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mobile or email is required.');

        app(UserAuthService::class)->register([
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    public function test_register_rejects_blank_contact_strings_as_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mobile or email is required.');

        app(UserAuthService::class)->register([
            'mobile' => '   ',
            'email' => '   ',
            'password' => 'secret123',
        ], '127.0.0.1');
    }

    public function test_register_rejects_short_password(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 6 characters.');

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
        $this->expectExceptionMessage('Email already exists.');

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
            $this->assertSame('Mobile already exists.', $exception->getMessage());
        }

        try {
            $service->register([
                'mobile' => '13800000003',
                'email' => 'person2@example.com',
                'password' => 'secret123',
            ], '127.0.0.1');

            $this->fail('Expected duplicate email registration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email already exists.', $exception->getMessage());
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
            $this->assertSame('Mobile already exists.', $exception->getMessage());
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
            $this->assertSame('Email already exists.', $exception->getMessage());
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
