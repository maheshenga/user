<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserOpsVisibilityTest extends TestCase
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

        DB::table('system_admin')->updateOrInsert(
            ['id' => 77],
            ['status' => 1, 'auth_ids' => '']
        );

        $this->withSession([
            'admin.id' => 77,
            'admin.expire_time' => true,
        ]);
    }

    public function test_user_ops_menu_sync_creates_visible_menu_entries(): void
    {
        $this->artisan('user:ops-menu:sync')
            ->expectsOutputToContain('synced=13')
            ->assertExitCode(0);

        $parent = DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', 'User Operations')
            ->where('href', '')
            ->whereNull('delete_time')
            ->first();

        $this->assertNotNull($parent);
        $this->assertSame(1, (int) $parent->status);

        foreach ($this->expectedMenuEntries() as $href => $title) {
            $this->assertDatabaseHas('system_menu', [
                'pid' => $parent->id,
                'title' => $title,
                'href' => $href,
                'status' => 1,
                'delete_time' => null,
            ]);
        }
    }

    public function test_user_ops_menu_sync_is_idempotent(): void
    {
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('system_menu')
                ->where('pid', 0)
                ->where('title', 'User Operations')
                ->where('href', '')
                ->whereNull('delete_time')
                ->count()
        );

        foreach ($this->expectedMenuEntries() as $href => $title) {
            $this->assertSame(
                1,
                DB::table('system_menu')
                    ->where('href', $href)
                    ->where('title', $title)
                    ->whereNull('delete_time')
                    ->count(),
                "Expected exactly one menu row for [{$href}]."
            );
        }
    }

    private function expectedMenuEntries(): array
    {
        return [
            'user/dashboard/index' => 'Overview',
            'user/account/index' => 'User Accounts',
            'user/invite/index' => 'Invite Codes',
            'user/invite/relations' => 'Invite Relations',
            'user/vip-plan/index' => 'VIP Plans',
            'user/activation-code/index' => 'Activation Codes',
            'user/activation-code/redemptions' => 'Activation Redemptions',
            'user/balance/index' => 'Balance Ledger',
            'user/commission/index' => 'Affiliate Commissions',
            'user/withdrawal/index' => 'Withdrawal Review',
            'user/risk-event/index' => 'Risk Events',
            'user/security-log/index' => 'Security Logs',
            'user/notification-outbox/index' => 'Notification Outbox',
        ];
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 120)->default('');
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
