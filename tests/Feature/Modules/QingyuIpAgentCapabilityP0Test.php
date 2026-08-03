<?php

namespace Tests\Feature\Modules;

use App\Models\UserAccount;
use App\Modules\ModuleInstaller;
use App\Providers\AppServiceProvider;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use App\User\VipService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\QingyuIpAgent\Services\VideoParserService;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class QingyuIpAgentCapabilityP0Test extends TestCase
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
        $this->enableQingyuModule();
    }

    public function test_content_status_requires_authentication_and_rewrite_ability(): void
    {
        $this->getJson('/api/v1/modules/qingyu-ip-agent/content/status')
            ->assertUnauthorized();

        [$account, $accessToken] = $this->createVipUser('p0-ability@example.com');
        $token = PersonalAccessToken::findToken($accessToken);
        $this->assertNotNull($token);
        $token->forceFill(['abilities' => ['module:qingyu_ip_agent']])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($accessToken)
            ->getJson('/api/v1/modules/qingyu-ip-agent/content/status')
            ->assertForbidden()
            ->assertJsonPath('code', 'ability_denied');
        $this->assertSame((int) $account->id, (int) $token->tokenable_id);
    }

    public function test_content_status_returns_only_public_platform_capability_data(): void
    {
        $this->configureRewriteProvider();
        [, $accessToken] = $this->createVipUser('p0-status@example.com');

        $response = $this->withToken($accessToken)
            ->getJson('/api/v1/modules/qingyu-ip-agent/content/status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'platform-managed')
            ->assertJsonPath('data.parse_available', true)
            ->assertJsonPath('data.rewrite_available', true)
            ->assertJsonPath('data.model', 'test-rewrite-model');

        $json = $response->getContent();
        $this->assertStringNotContainsString('provider-secret-key', $json);
        $this->assertStringNotContainsString('https://dashscope.example.test', $json);
    }

    public function test_content_status_rejects_expired_vip_and_reports_unconfigured_rewrite(): void
    {
        [, $expiredToken] = $this->createVipUser('p0-expired@example.com', false);
        $this->withToken($expiredToken)
            ->getJson('/api/v1/modules/qingyu-ip-agent/content/status')
            ->assertForbidden()
            ->assertJsonPath('code', 'vip_required');

        $this->app['auth']->forgetGuards();
        [, $activeToken] = $this->createVipUser('p0-unconfigured@example.com');
        Config::set('qingyu_ip_agent.llm.base_url', '');
        Config::set('qingyu_ip_agent.llm.api_key', '');
        Config::set('qingyu_ip_agent.llm.model', '');

        $this->withToken($activeToken)
            ->getJson('/api/v1/modules/qingyu-ip-agent/content/status')
            ->assertOk()
            ->assertJsonPath('data.parse_available', true)
            ->assertJsonPath('data.rewrite_available', false)
            ->assertJsonPath('data.model', '平台托管模型');
    }

    public function test_rewrite_returns_stable_provider_errors_without_logging_copy_or_key(): void
    {
        $this->configureRewriteProvider();
        [, $accessToken] = $this->createVipUser('p0-rewrite-errors@example.com');
        $copy = '这是绝不能出现在审计日志里的原始改写文案。';

        Http::fake(static function (): never {
            throw new ConnectionException('provider timeout with provider-secret-key');
        });

        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-rewrite-timeout')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/rewrite', ['message' => $copy])
            ->assertStatus(503)
            ->assertJsonPath('code', 'content_rewrite_unavailable')
            ->assertJsonPath('request_id', 'p0-rewrite-timeout');

        $audit = (string) DB::table('qingyu_ip_agent_operation_logs')
            ->where('action', 'client.rewrite')
            ->latest('id')
            ->value('masked_payload_json');
        $error = (string) DB::table('qingyu_ip_agent_operation_logs')
            ->where('action', 'client.rewrite')
            ->latest('id')
            ->value('error_message');
        $this->assertStringNotContainsString($copy, $audit);
        $this->assertStringNotContainsString('provider-secret-key', $audit.$error);
    }

    public function test_rewrite_distinguishes_provider_rejection_and_empty_result(): void
    {
        $this->configureRewriteProvider();
        [, $accessToken] = $this->createVipUser('p0-rewrite-response@example.com');

        Http::fakeSequence()
            ->push(['message' => 'forbidden'], 403)
            ->push(['choices' => []], 200);
        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-rewrite-rejected')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/rewrite', ['message' => '改写这段文案'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'content_rewrite_rejected');

        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-rewrite-empty')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/rewrite', ['message' => '改写另一段文案'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'content_rewrite_empty');
    }

    public function test_video_parser_rejects_private_dns_targets_before_http_request(): void
    {
        $this->app->bind(VideoParserService::class, fn ($app): VideoParserService => new VideoParserService(
            $app->make(VipService::class),
            static fn (string $host): array => ['127.0.0.1']
        ));
        [, $accessToken] = $this->createVipUser('p0-private-dns@example.com');
        Http::fake();

        $this->withToken($accessToken)
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', [
                'url' => 'https://www.douyin.com/video/7639590279997132072',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'content_parse_failed');

        Http::assertNothingSent();
    }

    public function test_video_parser_rejects_non_text_and_declared_oversized_responses(): void
    {
        $this->app->bind(VideoParserService::class, fn ($app): VideoParserService => new VideoParserService(
            $app->make(VipService::class),
            static fn (string $host): array => ['8.8.8.8']
        ));
        [, $accessToken] = $this->createVipUser('p0-response-bounds@example.com');
        $url = 'https://www.douyin.com/video/7639590279997132072';
        $html = '<html><head><meta name="description" content="不应被接受的文案"></head></html>';

        Http::fake([
            $url => Http::response($html, 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);
        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-non-text')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', ['url' => $url])
            ->assertUnprocessable();

        Http::fake([
            $url => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Length' => '1048577',
            ]),
            '*' => Http::response('', 404),
        ]);
        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-oversized')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', ['url' => $url])
            ->assertUnprocessable();
    }

    public function test_video_parser_audit_does_not_store_input_copy_or_url(): void
    {
        [, $accessToken] = $this->createVipUser('p0-parse-audit@example.com');
        $copy = '这是一段不应进入操作日志的原始视频文案';
        $url = 'https://www.douyin.com/video/7639590279997132072';
        $input = $copy.' '.$url;

        $this->withToken($accessToken)
            ->withHeader('X-Request-ID', 'p0-parse-audit')
            ->postJson('/api/v1/modules/qingyu-ip-agent/content/parse', ['text' => $input])
            ->assertOk()
            ->assertJsonPath('data.content', $copy);

        $audit = (string) DB::table('qingyu_ip_agent_operation_logs')
            ->where('action', 'client.video.parse')
            ->latest('id')
            ->value('masked_payload_json');
        $payload = json_decode($audit, true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($copy, $audit);
        $this->assertStringNotContainsString($url, $audit);
        $this->assertArrayNotHasKey('text', $payload);
        $this->assertSame(mb_strlen($input, 'UTF-8'), $payload['input_length'] ?? null);
    }

    private function enableQingyuModule(): void
    {
        $this->installApprovedModule('qingyu_ip_agent', 1);
        app(ModuleInstaller::class)->enable('qingyu_ip_agent', 1);
        (new AppServiceProvider(app()))->boot();
    }

    /** @return array{0: UserAccount, 1: string} */
    private function createVipUser(string $email, bool $activeVip = true): array
    {
        $registered = app(UserAuthService::class)->register([
            'email' => $email,
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');
        $account = UserAccount::query()->findOrFail((int) $registered['user']['id']);
        $account->forceFill([
            'vip_level' => $activeVip ? 1 : 0,
            'vip_expires_at' => $activeVip ? now()->addDays(9) : now()->subMinute(),
            'update_time' => time(),
        ])->save();
        $tokens = app(UserApiTokenService::class)->issue(
            $account,
            'qingyu_ip_agent',
            ['device_id' => 'p0-'.str_replace(['@', '.'], '-', $email)],
            '127.0.0.1',
            'Qingyu P0 Test'
        );

        return [$account->refresh(), $tokens['access_token']];
    }

    private function configureRewriteProvider(): void
    {
        Config::set('qingyu_ip_agent.llm.base_url', 'https://dashscope.example.test/compatible-mode/v1');
        Config::set('qingyu_ip_agent.llm.api_key', 'provider-secret-key');
        Config::set('qingyu_ip_agent.llm.model', 'test-rewrite-model');
        Config::set('qingyu_ip_agent.llm.allowed_hosts', ['dashscope.example.test']);
        Config::set('qingyu_ip_agent.llm.timeout', 30);
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
