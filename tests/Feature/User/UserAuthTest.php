<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
