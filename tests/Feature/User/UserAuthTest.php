<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
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
