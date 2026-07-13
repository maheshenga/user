<?php

namespace Tests\Feature\Modules;

use App\Modules\ModuleAutoloader;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRepository;
use App\Models\VipPlan;
use App\User\ActivationCodeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\User\UserAuthService;
use Modules\QingyuIpAgent\Services\AuditLogService;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class QingyuIpAgentModuleTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));

        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        Config::set('modules.path', base_path('modules'));

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();
    }

    public function test_qingyu_ip_agent_manifest_is_discovered_and_starts_pending_review(): void
    {
        $manifest = app(ModuleManager::class)->manifest('qingyu_ip_agent');

        $this->assertNotNull($manifest);
        $this->assertSame('qingyu_ip_agent', $manifest->name());
        $this->assertSame('轻语IP智能体', $manifest->title());
        $this->assertSame('qingyu_ip_agent', $manifest->adminPrefix());
        $this->assertSame('1.2.0', $manifest->version());
        $this->assertSame('private', $manifest->type());
        $this->assertContains('menu:write', $manifest->permissions());
        $this->assertContains('node:write', $manifest->permissions());

        app(ModuleRepository::class)->upsertDiscovered($manifest);

        $this->assertDatabaseHas('system_module', [
            'name' => 'qingyu_ip_agent',
            'status' => 'pending_review',
            'admin_prefix' => 'qingyu_ip_agent',
        ]);
    }

    public function test_qingyu_ip_agent_installs_imports_menus_runs_migrations_and_enables(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);

        $this->assertDatabaseHas('system_module', [
            'name' => 'qingyu_ip_agent',
            'status' => 'installed',
        ]);
        $this->assertTrue(Schema::hasTable('qingyu_ip_agent_settings'));
        $this->assertTrue(Schema::hasTable('qingyu_ip_agent_operation_logs'));
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'qingyu_ip_agent',
            'migration' => '2026_07_07_000001_create_qingyu_ip_agent_settings_table.php',
        ]);
        $this->assertDatabaseHas('system_module_migration', [
            'module' => 'qingyu_ip_agent',
            'migration' => '2026_07_07_000002_create_qingyu_ip_agent_operation_logs_table.php',
        ]);
        $this->assertDatabaseHas('system_menu', [
            'href' => 'qingyu_ip_agent/dashboard/index',
        ]);
        $this->assertDatabaseHas('system_menu', [
            'href' => 'qingyu_ip_agent/member/index',
        ]);
        $this->assertDatabaseHas('system_menu', [
            'href' => 'qingyu_ip_agent/activation-code/index',
        ]);
        $this->assertDatabaseMissing('system_menu', [
            'href' => 'admin/qingyu_ip_agent',
        ]);
        $this->assertDatabaseMissing('system_menu', [
            'href' => 'admin/qingyu_ip_agent/',
        ]);

        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $this->assertDatabaseHas('system_module', [
            'name' => 'qingyu_ip_agent',
            'status' => 'enabled',
        ]);
    }

    public function test_qingyu_ip_agent_audit_log_masks_sensitive_payloads(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleAutoloader::class)->register(app(ModuleManager::class)->manifest('qingyu_ip_agent'));

        app(AuditLogService::class)->record(
            action: 'activation_code.generate',
            targetType: 'activation_code_batch',
            targetId: 12,
            payload: [
                'email' => 'person@example.com',
                'mobile' => '13800000001',
                'password' => 'secret123',
                'newPassword' => 'new-secret123',
                'token' => 'token-raw-value',
                'code' => 'EA8-ABCD-EFGH-IJKL-MNPQ-RSTU-WXYZ',
                'safe_note' => 'visible',
            ],
            result: 'success'
        );

        $payload = (string) DB::table('qingyu_ip_agent_operation_logs')->value('masked_payload_json');

        $this->assertStringNotContainsString('person@example.com', $payload);
        $this->assertStringNotContainsString('13800000001', $payload);
        $this->assertStringNotContainsString('secret123', $payload);
        $this->assertStringNotContainsString('new-secret123', $payload);
        $this->assertStringNotContainsString('token-raw-value', $payload);
        $this->assertStringNotContainsString('EA8-ABCD-EFGH-IJKL-MNPQ-RSTU-WXYZ', $payload);
        $this->assertStringContainsString('p***n@example.com', $payload);
        $this->assertStringContainsString('138****0001', $payload);
        $this->assertStringContainsString('EA8-****-WXYZ', $payload);
        $this->assertStringContainsString('visible', $payload);
    }

    public function test_qingyu_ip_agent_admin_can_create_activation_batches_and_generate_codes(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();

        $plan = VipPlan::query()->create([
            'name' => 'Module VIP',
            'level' => 2,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $batchResponse = $this->postJson('/admin/qingyu_ip_agent/activation-code/createBatch', [
            'name' => 'Module Codes',
            'vip_plan_id' => $plan->id,
            'duration_days' => 30,
            'total_count' => 3,
            'status' => 'active',
            'is_commissionable' => 1,
            'first_level_reward' => 8,
            'second_level_reward' => 3,
        ]);
        $batchResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.total_count', 3);

        $generateResponse = $this->postJson('/admin/qingyu_ip_agent/activation-code/generateCodes', [
            'batch_id' => $batchResponse->json('data.id'),
            'count' => 2,
        ]);
        $generateResponse->assertOk()
            ->assertJsonPath('code', 1);
        $this->assertCount(2, $generateResponse->json('data.codes'));

        $this->assertDatabaseHas('activation_code_batch', [
            'name' => 'Module Codes',
            'total_count' => 3,
            'generated_count' => 2,
        ]);
        $this->assertDatabaseHas('qingyu_ip_agent_operation_logs', [
            'action' => 'activation_code.generate',
            'target_type' => 'activation_code_batch',
            'result' => 'success',
        ]);
    }

    public function test_qingyu_ip_agent_business_dashboard_route_is_not_module_center_route(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();

        $response = $this->get('/admin/qingyu_ip_agent/dashboard/index');

        $response->assertOk();
        $response->assertSee('会员数', false);
        $response->assertSee('激活码批次', false);
    }

    public function test_qingyu_ip_agent_client_routes_register_login_profile_without_admin_session(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $register = $this->postJson('/admin/qingyu_ip_agent/client/register', [
            'email' => 'desktop-module@example.com',
            'password' => 'secret123',
        ]);

        $register->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'desktop-module@example.com')
            ->assertJsonPath('data.user.source_module', 'qingyu_ip_agent')
            ->assertJsonPath('data.userInfo.email', 'desktop-module@example.com')
            ->assertSessionHas('user.email', 'desktop-module@example.com');

        $this->assertDatabaseHas('user_account', [
            'email' => 'desktop-module@example.com',
            'source_module' => 'qingyu_ip_agent',
        ]);
        $this->assertDatabaseHas('qingyu_ip_agent_operation_logs', [
            'action' => 'client.register',
            'result' => 'success',
        ]);

        session()->forget('user');

        $login = $this->postJson('/admin/qingyu_ip_agent/client/login', [
            'account' => 'desktop-module@example.com',
            'password' => 'secret123',
        ]);

        $login->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'desktop-module@example.com')
            ->assertJsonPath('data.userInfo.email', 'desktop-module@example.com')
            ->assertSessionHas('user.email', 'desktop-module@example.com');

        $profile = $this->getJson('/admin/qingyu_ip_agent/client/profile');

        $profile->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.email', 'desktop-module@example.com')
            ->assertJsonPath('data.userInfo.email', 'desktop-module@example.com');
    }

    public function test_qingyu_ip_agent_client_route_redeems_activation_code_and_records_safe_audit(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $user = app(UserAuthService::class)->register([
            'email' => 'desktop-activate@example.com',
            'password' => 'secret123',
            'source_module' => 'qingyu_ip_agent',
        ], '127.0.0.1');
        session(['user' => $user['user']]);

        $plan = VipPlan::query()->create([
            'name' => 'Desktop Module VIP',
            'level' => 2,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $batch = app(ActivationCodeService::class)->createBatch([
            'name' => 'Desktop Client Codes',
            'vip_plan_id' => $plan->id,
            'total_count' => 1,
            'status' => 'active',
        ], 1);
        $generated = app(ActivationCodeService::class)->generateCodes((int) $batch['id'], 1, 1);
        $plainCode = $generated['codes'][0];

        $response = $this->postJson('/admin/qingyu_ip_agent/client/activate', [
            'code' => $plainCode,
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.userInfo.email', 'desktop-activate@example.com')
            ->assertJsonPath('data.userInfo.is_vip', 1)
            ->assertJsonPath('data.vip.vip_level', 2);
        $this->assertGreaterThan(0, (int) $response->json('data.userInfo.daysRemaining'));

        $this->assertDatabaseHas('activation_code_redemption', [
            'user_id' => $user['user']['id'],
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('qingyu_ip_agent_operation_logs', [
            'action' => 'client.activate',
            'result' => 'success',
        ]);

        $payload = (string) DB::table('qingyu_ip_agent_operation_logs')
            ->where('action', 'client.activate')
            ->value('masked_payload_json');
        $this->assertStringNotContainsString($plainCode, $payload);
    }

    public function test_qingyu_ip_agent_client_route_extracts_douyin_caption_for_vip_member(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $registered = app(UserAuthService::class)->register([
            'email' => 'desktop-parser@example.com',
            'password' => 'secret123',
            'source_module' => 'qingyu_ip_agent',
        ], '127.0.0.1');
        $user = $registered['user'];
        DB::table('user_account')->where('id', $user['id'])->update([
            'vip_level' => 1,
            'vip_expires_at' => now()->addDays(30),
            'update_time' => time(),
        ]);
        session(['user' => $user]);

        $url = 'https://www.douyin.com/jingxuan?modal_id=7639590279997132072';
        $caption = '这是一条来自抖音精选页面的测试文案 #轻语IP';
        $routerData = json_encode([
            'loaderData' => [
                'video_(id)/page' => [
                    'videoInfoRes' => [
                        'item_list' => [[
                            'aweme_id' => '7639590279997132072',
                            'desc' => $caption,
                            'author' => ['nickname' => '测试作者'],
                        ]],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Http::fake([
            $url => Http::response('<html><head><title>测试视频 - 抖音</title></head><body><script>window._ROUTER_DATA = '.$routerData.'</script></body></html>', 200),
        ]);

        $response = $this->postJson('/admin/qingyu_ip_agent/client/parseContent', [
            'url' => $url,
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.content', $caption)
            ->assertJsonPath('data.videoInfo.platform', 'douyin')
            ->assertJsonPath('data.videoInfo.author', '测试作者')
            ->assertJsonPath('data.videoInfo.source', 'douyin_router');
        $this->assertDatabaseHas('qingyu_ip_agent_operation_logs', [
            'action' => 'client.video.parse',
            'result' => 'success',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === $url);
    }

    public function test_qingyu_ip_agent_video_parser_does_not_follow_redirects_outside_supported_hosts(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $registered = app(UserAuthService::class)->register([
            'email' => 'desktop-parser-redirect@example.com',
            'password' => 'secret123',
            'source_module' => 'qingyu_ip_agent',
        ], '127.0.0.1');
        $user = $registered['user'];
        DB::table('user_account')->where('id', $user['id'])->update([
            'vip_level' => 1,
            'vip_expires_at' => now()->addDays(30),
            'update_time' => time(),
        ]);
        session(['user' => $user]);

        $url = 'https://www.douyin.com/jingxuan?modal_id=7639590279997132072';
        Http::fake([
            $url => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
            '*' => Http::response('', 404),
        ]);

        $response = $this->postJson('/admin/qingyu_ip_agent/client/parseContent', ['url' => $url]);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '链接有效，但平台未返回可提取的文案，请稍后重试或粘贴完整分享文本。');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table): void {
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
            ['group' => 'site', 'name' => 'iframe_open_top', 'value' => '0'],
        ]);
    }
}
