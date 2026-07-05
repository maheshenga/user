<?php

namespace Tests\Feature\User;

use App\Models\UserPasswordReset;
use App\Models\UserSecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
