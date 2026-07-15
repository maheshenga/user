<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\SystemModule;
use App\Models\UserAccount;
use App\Models\VipPlan;
use App\Modules\ModuleAutoloader;
use App\Modules\ModuleExecutionContext;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleRepository;
use App\Modules\ModuleViewRegistrar;
use App\Providers\AppServiceProvider;
use App\User\ActivationCodeService;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use App\User\UserModuleMembershipService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\QingyuIpAgent\Services\ActivationCodeOpsService;
use Modules\QingyuIpAgent\Services\AuditLogService;
use Modules\QingyuIpAgent\Services\DashboardService;
use Modules\QingyuIpAgent\Services\MemberOpsService;
use Modules\QingyuIpAgent\Services\RewriteService;
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
        $this->assertSame('1.5.0', $manifest->version());
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

    public function test_enabled_module_entry_provider_registers_module_config(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $this->assertNull(Config::get('qingyu_ip_agent.llm.allowed_hosts'));

        (new AppServiceProvider(app()))->boot();

        $this->assertSame(
            ['dashscope.aliyuncs.com'],
            Config::get('qingyu_ip_agent.llm.allowed_hosts')
        );
    }

    public function test_versioned_qingyu_api_exposes_public_bootstrap_and_scoped_protected_routes(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        (new AppServiceProvider(app()))->boot();

        $this->getJson('/api/v1/modules/qingyu-ip-agent/bootstrap')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.module', 'qingyu_ip_agent');

        $this->post('/api/v1/modules/qingyu-ip-agent/content/parse')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/v1/modules/qingyu-ip-agent/activation-codes/redeem', [
            'code' => 'missing-code',
        ])->assertUnauthorized();

        $user = app(UserAuthService::class)->register([
            'email' => 'module-scope@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $account = UserAccount::query()->findOrFail((int) $user['user']['id']);
        $wrongScope = $account->createToken(
            'wrong-module-scope',
            ['activation:redeem'],
            now()->addMinutes(15)
        );

        $this->withToken($wrongScope->plainTextToken)
            ->postJson('/api/v1/modules/qingyu-ip-agent/activation-codes/redeem', [
                'code' => 'missing-code',
            ])->assertForbidden()
            ->assertJsonPath('code', 'ability_denied');

        $disabledToken = $account->createToken(
            'disabled-module-user',
            ['module:qingyu_ip_agent', 'activation:redeem'],
            now()->addMinutes(15)
        );
        $account->update(['status' => 'disabled']);
        $this->app['auth']->forgetGuards();

        $this->withToken($disabledToken->plainTextToken)
            ->postJson('/api/v1/modules/qingyu-ip-agent/activation-codes/redeem', [
                'code' => 'missing-code',
            ])->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');
        $this->assertNull(PersonalAccessToken::findToken($disabledToken->plainTextToken));
    }

    public function test_versioned_qingyu_api_redeems_activation_code_with_scoped_token(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        (new AppServiceProvider(app()))->boot();

        $registered = app(UserAuthService::class)->register([
            'email' => 'api-activate@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $account = UserAccount::query()->findOrFail((int) $registered['user']['id']);
        $account->forceFill([
            'vip_level' => 1,
            'vip_expires_at' => now()->addDay(),
            'update_time' => time(),
        ])->save();
        $tokens = app(UserApiTokenService::class)->issue(
            $account,
            'qingyu_ip_agent',
            ['device_id' => 'module-api-device', 'device_name' => 'Module API Desktop'],
            '127.0.0.1',
            'Module API Feature Test'
        );

        $plan = VipPlan::query()->create([
            'name' => 'Module API VIP',
            'level' => 3,
            'duration_days' => 7,
            'price' => 29,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $batch = app(ActivationCodeService::class)->createBatch([
            'name' => 'Module API Codes',
            'vip_plan_id' => $plan->id,
            'total_count' => 1,
            'status' => 'active',
        ], 1, 'qingyu_ip_agent');
        $generated = app(ActivationCodeService::class)->generateCodes((int) $batch['id'], 1, 1);

        $tokenable = PersonalAccessToken::findToken($tokens['access_token'])->tokenable;
        $this->assertInstanceOf(UserAccount::class, $tokenable);
        $this->assertSame('active', $tokenable->status);
        $this->assertNotNull($tokenable->fresh());

        $response = $this->withToken($tokens['access_token'])
            ->postJson('/api/v1/modules/qingyu-ip-agent/activation-codes/redeem', [
                'code' => $generated['codes'][0],
            ]);
        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.userInfo.email', 'api-activate@example.com')
            ->assertJsonPath('data.userInfo.is_vip', 1)
            ->assertJsonPath('data.vip.vip_level', 3);
    }

    public function test_versioned_qingyu_api_replays_idempotent_requests_and_enforces_daily_quota(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        (new AppServiceProvider(app()))->boot();
        Config::set('modules.api_daily_quotas.qingyu_ip_agent', ['content.parse' => 1]);

        $registered = app(UserAuthService::class)->register([
            'email' => 'api-idempotent@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $account = UserAccount::query()->findOrFail((int) $registered['user']['id']);
        $account->forceFill([
            'vip_level' => 1,
            'vip_expires_at' => now()->addDay(),
            'update_time' => time(),
        ])->save();
        $tokens = app(UserApiTokenService::class)->issue(
            $account,
            'qingyu_ip_agent',
            ['device_id' => 'module-idempotent-device'],
            '127.0.0.1',
            'Module Idempotency Test'
        );
        $payload = [
            'url' => 'https://www.douyin.com/video/7639590279997132072',
            'text' => '这是需要提取的短视频文案 https://www.douyin.com/video/7639590279997132072',
        ];

        $first = $this->withToken($tokens['access_token'])
            ->withHeader('X-Request-ID', 'parse-request-0001')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', $payload);
        $first->assertOk()
            ->assertJsonPath('request_id', 'parse-request-0001')
            ->assertJsonPath('data.content', '这是需要提取的短视频文案');

        $second = $this->withToken($tokens['access_token'])
            ->withHeader('X-Request-ID', 'parse-request-0001')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', $payload);
        $second->assertOk()
            ->assertJsonPath('request_id', 'parse-request-0001')
            ->assertJsonPath('data.content', '这是需要提取的短视频文案');
        $this->assertSame(1, DB::table('module_api_request')->count());
        $this->assertSame(1, DB::table('qingyu_ip_agent_operation_logs')->where('action', 'client.video.parse')->count());
        $this->assertSame(
            'parse-request-0001',
            DB::table('qingyu_ip_agent_operation_logs')->where('action', 'client.video.parse')->value('request_id')
        );

        $this->withToken($tokens['access_token'])
            ->withHeader('X-Request-ID', 'parse-request-0001')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', array_merge($payload, ['text' => '不同载荷']))
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->withToken($tokens['access_token'])
            ->withHeader('X-Request-ID', 'parse-request-0002')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', $payload)
            ->assertStatus(429)
            ->assertJsonPath('code', 'quota_exceeded');
    }

    public function test_versioned_qingyu_api_returns_typed_errors_with_request_id(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        (new AppServiceProvider(app()))->boot();
        $registered = app(UserAuthService::class)->register([
            'email' => 'api-typed-error@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $account = UserAccount::query()->findOrFail((int) $registered['user']['id']);
        $tokens = app(UserApiTokenService::class)->issue(
            $account,
            'qingyu_ip_agent',
            ['device_id' => 'module-typed-error-device'],
            '127.0.0.1',
            'Module Typed Error Test'
        );

        $this->withToken($tokens['access_token'])
            ->withHeader('X-Request-ID', 'activate-request-0001')
            ->postJson('/api/v1/modules/qingyu-ip-agent/activation-codes/redeem', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'activation_invalid')
            ->assertJsonPath('request_id', 'activate-request-0001');
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
                'activationCode' => 'EA8-CAMEL-CASE-SECRET-9XYZ',
                'refresh-token' => 'refresh-raw-456',
                'api_key' => 'api-key-raw-value',
                'clientSecret' => 'client-secret-raw-value',
                'credentials' => ['raw' => 'credential-array-raw-value'],
                'metadata' => ['sessionToken' => 'nested-session-raw-value'],
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
        $this->assertStringNotContainsString('EA8-CAMEL-CASE-SECRET-9XYZ', $payload);
        $this->assertStringNotContainsString('refresh-raw-456', $payload);
        $this->assertStringNotContainsString('api-key-raw-value', $payload);
        $this->assertStringNotContainsString('client-secret-raw-value', $payload);
        $this->assertStringNotContainsString('credential-array-raw-value', $payload);
        $this->assertStringNotContainsString('nested-session-raw-value', $payload);
        $this->assertStringContainsString('p***n@example.com', $payload);
        $this->assertStringContainsString('138****0001', $payload);
        $this->assertStringContainsString('EA8-****-WXYZ', $payload);
        $this->assertStringContainsString('EA8-****-9XYZ', $payload);
        $this->assertStringContainsString('visible', $payload);
    }

    public function test_qingyu_ip_agent_audit_log_truncates_multibyte_errors_to_column_limit(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleAutoloader::class)->register(app(ModuleManager::class)->manifest('qingyu_ip_agent'));

        app(AuditLogService::class)->record(
            action: 'client.password-reset',
            targetType: 'user',
            targetId: 12,
            payload: [],
            result: 'failed',
            errorMessage: str_repeat("\u{9519}\u{8BEF}", 300)
        );

        $error = (string) DB::table('qingyu_ip_agent_operation_logs')->value('error_message');

        $this->assertSame(500, mb_strlen($error, 'UTF-8'));
        $this->assertStringStartsWith("\u{9519}\u{8BEF}", $error);
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
        app(ModuleViewRegistrar::class)->registerEnabled();

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

    public function test_qingyu_services_only_expose_owned_members_and_activation_codes(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        app(ModuleAutoloader::class)->register(app(ModuleManager::class)->manifest('qingyu_ip_agent'));
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $runAsModule = fn (callable $callback): mixed => app(ModuleExecutionContext::class)
            ->run($module, 'qingyu-ownership-test', $callback);

        $qingyuUser = app(UserAuthService::class)->register([
            'email' => 'qingyu-owner@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $coreMember = app(UserAuthService::class)->register([
            'email' => 'core-member@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'core');
        $nonMember = app(UserAuthService::class)->register([
            'email' => 'core-non-member@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'core');
        app(UserModuleMembershipService::class)->grant(
            (int) $coreMember['user']['id'],
            'qingyu_ip_agent',
            'admin_grant',
            1
        );

        $members = app(MemberOpsService::class)->paginate([], 1, 20);
        $this->assertSame(2, $members['total']);
        $memberIds = array_column($members['list'], 'id');
        $this->assertContains((int) $qingyuUser['user']['id'], $memberIds);
        $this->assertContains((int) $coreMember['user']['id'], $memberIds);
        $this->assertNotContains((int) $nonMember['user']['id'], $memberIds);
        $this->assertSame(
            'core',
            collect($members['list'])->firstWhere('id', (int) $coreMember['user']['id'])['source_module']
        );
        $this->assertSame(
            (int) $coreMember['user']['id'],
            $runAsModule(
                fn (): array => app(MemberOpsService::class)->detail((int) $coreMember['user']['id'])
            )['id']
        );
        try {
            app(MemberOpsService::class)->detail((int) $nonMember['user']['id']);
            $this->fail('Expected Qingyu member detail to reject a non-member.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $plan = VipPlan::query()->create([
            'name' => 'Ownership VIP',
            'level' => 2,
            'duration_days' => 30,
            'price' => 99,
            'status' => 'active',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        $coreBatch = app(ActivationCodeService::class)->createBatch([
            'name' => 'Core Codes',
            'vip_plan_id' => $plan->id,
            'total_count' => 1,
            'status' => 'active',
        ], 1);
        $qingyuBatch = $runAsModule(fn (): array => app(ActivationCodeOpsService::class)->createBatch([
            'name' => 'Qingyu Codes',
            'vip_plan_id' => $plan->id,
            'total_count' => 1,
            'status' => 'active',
        ], 1));

        $this->assertDatabaseHas('activation_code_batch', [
            'id' => $coreBatch['id'],
            'owner_module' => 'core',
        ]);
        $this->assertDatabaseHas('activation_code_batch', [
            'id' => $qingyuBatch['id'],
            'owner_module' => 'qingyu_ip_agent',
        ]);
        $this->assertSame(1, app(ActivationCodeOpsService::class)->batches([], 1, 20)['total']);
        $this->assertSame(2, app(DashboardService::class)->summary()['member_count']);
        $this->assertSame(1, app(DashboardService::class)->summary()['activation_batch_count']);

        try {
            $runAsModule(fn (): array => app(ActivationCodeOpsService::class)->generateCodes((int) $coreBatch['id'], 1, 1));
            $this->fail('Expected Qingyu code generation to reject a core batch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('不属于当前模块', $exception->getMessage());
        }

        $coreGenerated = app(ActivationCodeService::class)->generateCodes((int) $coreBatch['id'], 1, 1);
        $qingyuGenerated = $runAsModule(
            fn (): array => app(ActivationCodeOpsService::class)->generateCodes((int) $qingyuBatch['id'], 1, 1)
        );
        $this->assertSame(1, app(ActivationCodeOpsService::class)->codes([], 1, 20)['total']);

        try {
            app(ActivationCodeService::class)->redeem([
                'code' => $coreGenerated['codes'][0],
            ], (int) $qingyuUser['user']['id'], '127.0.0.1', 'qingyu_ip_agent');
            $this->fail('Expected Qingyu redemption to reject a core activation code.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('激活码无效。', $exception->getMessage());
        }

        app(ActivationCodeService::class)->redeem([
            'code' => $qingyuGenerated['codes'][0],
        ], (int) $qingyuUser['user']['id'], '127.0.0.1', 'qingyu_ip_agent');
        $this->assertDatabaseHas('activation_code_redemption', [
            'batch_id' => $qingyuBatch['id'],
            'owner_module' => 'qingyu_ip_agent',
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
        app(ModuleViewRegistrar::class)->registerEnabled();

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

    public function test_qingyu_legacy_client_login_rejects_accounts_owned_by_other_modules(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        app(UserAuthService::class)->register([
            'email' => 'other-module-login@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'core');

        $this->postJson('/admin/qingyu_ip_agent/client/login', [
            'account' => 'other-module-login@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '账号或密码错误。')
            ->assertSessionMissing('user');
    }

    public function test_qingyu_legacy_client_login_accepts_core_attributed_member(): void
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
            'email' => 'core-attributed-qingyu-member@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'core');
        app(UserModuleMembershipService::class)->grant(
            (int) $registered['user']['id'],
            'qingyu_ip_agent',
            'admin_grant',
            1
        );

        $this->postJson('/admin/qingyu_ip_agent/client/login', [
            'account' => 'core-attributed-qingyu-member@example.com',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.user.source_module', 'core')
            ->assertSessionHas('user.email', 'core-attributed-qingyu-member@example.com');
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
        ], '127.0.0.1', 'qingyu_ip_agent');
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
        ], 1, 'qingyu_ip_agent');
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
        ], '127.0.0.1', 'qingyu_ip_agent');
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
        ], '127.0.0.1', 'qingyu_ip_agent');
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

    public function test_qingyu_ip_agent_client_route_rewrites_copy_through_server_side_cloud_provider(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        Config::set('qingyu_ip_agent.llm.base_url', 'https://dashscope.example.test/compatible-mode/v1');
        Config::set('qingyu_ip_agent.llm.api_key', 'test-provider-key');
        Config::set('qingyu_ip_agent.llm.model', 'test-rewrite-model');
        Config::set('qingyu_ip_agent.llm.timeout', 30);
        Config::set('qingyu_ip_agent.llm.allowed_hosts', ['dashscope.example.test']);

        $registered = app(UserAuthService::class)->register([
            'email' => 'desktop-rewrite@example.com',
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $user = $registered['user'];
        DB::table('user_account')->where('id', $user['id'])->update([
            'vip_level' => 1,
            'vip_expires_at' => now()->addDays(30),
            'update_time' => time(),
        ]);
        session(['user' => $user]);

        Http::fake([
            'https://dashscope.example.test/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '这是服务端云模型返回的改写文案。'],
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/admin/qingyu_ip_agent/client/rewrite', [
            'message' => '请把这段短视频口播文案改写得更有记忆点。',
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.content', '这是服务端云模型返回的改写文案。')
            ->assertJsonPath('data.text', '这是服务端云模型返回的改写文案。')
            ->assertJsonPath('data.provider', 'module-cloud');
        $this->assertDatabaseHas('qingyu_ip_agent_operation_logs', [
            'action' => 'client.rewrite',
            'result' => 'success',
        ]);
        $auditPayload = (string) DB::table('qingyu_ip_agent_operation_logs')
            ->where('action', 'client.rewrite')
            ->value('masked_payload_json');
        $this->assertStringContainsString('message_length', $auditPayload);
        $this->assertStringNotContainsString('请把这段短视频口播文案改写得更有记忆点。', $auditPayload);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://dashscope.example.test/compatible-mode/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-provider-key')
                && $request['model'] === 'test-rewrite-model';
        });
    }

    public function test_qingyu_ip_agent_rewrite_service_rejects_undeclared_provider_hosts(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        app(ModuleAutoloader::class)->register(app(ModuleManager::class)->manifest('qingyu_ip_agent'));

        Config::set('qingyu_ip_agent.llm.base_url', 'https://unapproved.example.test/v1');
        Config::set('qingyu_ip_agent.llm.api_key', 'test-provider-key');
        Config::set('qingyu_ip_agent.llm.model', 'test-rewrite-model');
        Config::set('qingyu_ip_agent.llm.allowed_hosts', ['dashscope.aliyuncs.com']);
        Http::fake();

        try {
            app(RewriteService::class)->rewrite('测试文案');
            $this->fail('Expected an invalid provider URL exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('云端改写服务地址无效。', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_qingyu_ip_agent_client_route_streams_the_bundled_sample_audio(): void
    {
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
            SystemLog::class,
            CheckAuth::class,
        ]);
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);

        $response = $this->get('/admin/qingyu_ip_agent/client/sampleAudio');

        $response->assertOk()->assertHeader('content-type', 'audio/mpeg');
        $this->assertGreaterThan(1024, $response->baseResponse->getFile()->getSize());
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
