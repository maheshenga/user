<?php

namespace Tests\Feature\Modules;

use App\Contracts\Modules\ModuleWorkerClient;
use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use App\Modules\ModuleArtifactHasher;
use App\Modules\ModuleIdentity;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleReleaseSigner;
use App\Modules\ModuleRollbacker;
use App\Modules\ModuleRuntimeEligibility;
use App\Modules\Worker\HttpModuleWorkerClient;
use App\Modules\Worker\ModuleWorkerEligibility;
use App\Modules\Worker\ModuleWorkerRequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class ModuleWorkerContractTest extends TestCase
{
    use CreatesModuleTestSchema;

    private string $root;

    protected function setUp(): void
    {
        putenv('APP_KEY=base64:'.base64_encode(str_repeat('a', 32)));
        $_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
        $_SERVER['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));

        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->root = storage_path('framework/testing-module-worker');
        $this->deletePath($this->root);
        mkdir($this->root, 0777, true);

        Config::set('modules.path', $this->root);
        Config::set('cache.default', 'array');
        Config::set('modules.signing_active_key_id', 'release-v1');
        Config::set('modules.signing_keys', ['release-v1' => str_repeat('r', 32)]);
        Config::set('modules.worker', [
            'url' => 'https://worker.example.test',
            'protocol_version' => '1.0',
            'active_key_id' => 'worker-v1',
            'keys' => ['worker-v1' => str_repeat('w', 32)],
            'timeout_seconds' => 2,
            'connect_timeout_seconds' => 1,
            'max_response_bytes' => 1024,
            'clock_skew_seconds' => 300,
            'health_cache_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        $this->deletePath($this->root);

        parent::tearDown();
    }

    public function test_worker_client_is_bound_to_http_implementation(): void
    {
        $this->assertInstanceOf(HttpModuleWorkerClient::class, app(ModuleWorkerClient::class));
    }

    public function test_health_request_is_signed_with_fresh_replay_metadata_and_verifies_response(): void
    {
        $nonces = [];
        $this->fakeSignedWorker(function (Request $request) use (&$nonces): array {
            $this->assertSame('1.0', $this->header($request, 'X-Module-Worker-Protocol'));
            $this->assertSame('worker-v1', $this->header($request, 'X-Module-Worker-Key-Id'));
            $this->assertNotSame('', $this->header($request, 'X-Module-Worker-Timestamp'));
            $this->assertNotSame('', $this->header($request, 'X-Module-Worker-Nonce'));
            $this->assertNotSame('', $this->header($request, 'X-Module-Request-Id'));
            $this->assertNotSame('', $this->header($request, 'X-Module-Worker-Signature'));
            $this->assertTrue(app(ModuleWorkerRequestSigner::class)->verifyRequest(
                'GET',
                '/v1/health',
                $request->headers(),
                $request->body()
            ));
            $nonces[] = $this->header($request, 'X-Module-Worker-Nonce');

            return [
                'status' => 'ok',
                'protocol_version' => '1.0',
                'modules' => [],
            ];
        });

        $first = app(ModuleWorkerClient::class)->health();
        $second = app(ModuleWorkerClient::class)->health();

        $this->assertSame('ok', $first['status']);
        $this->assertSame('1.0', $second['protocol_version']);
        $this->assertCount(2, array_unique($nonces));
    }

    public function test_invoke_sends_scoped_identity_release_hash_and_returns_data(): void
    {
        [$module, $release] = $this->createExternalModule('invoke_module', 'installed', 'active');
        $releaseManifest = $release->manifest_json;
        $releaseManifest['external_domains'] = ['approved.example.test'];
        $releaseManifest['permissions'] = ['content:parse'];
        $release->forceFill(['manifest_json' => $releaseManifest])->save();
        $moduleManifest = $module->config_json;
        $moduleManifest['external_domains'] = ['mutable.example.test'];
        $moduleManifest['permissions'] = ['balance:write'];
        $module->forceFill(['config_json' => $moduleManifest])->save();
        $identity = new ModuleIdentity(
            (string) $module->name,
            (int) $release->id,
            'official',
            ['admin:*'],
            'identity-request'
        );
        $this->fakeSignedWorker(function (Request $request) use ($release): array {
            $payload = $request->data();
            $this->assertSame('invoke_module', $this->header($request, 'X-Module-Name'));
            $this->assertSame($release->artifact_hash, $this->header($request, 'X-Module-Release-Hash'));
            $this->assertSame('invoke-request', $this->header($request, 'X-Module-Request-Id'));
            $this->assertSame('content.parse', $payload['operation'] ?? null);
            $this->assertSame('community', $payload['trust_level'] ?? null);
            $this->assertSame(['content:parse'], $payload['capabilities'] ?? null);
            $this->assertSame(['approved.example.test'], $payload['external_domains'] ?? null);
            $this->assertSame(['url' => 'https://example.test/video'], $payload['payload'] ?? null);
            $this->assertTrue(app(ModuleWorkerRequestSigner::class)->verifyRequest(
                'POST',
                '/v1/invoke',
                $request->headers(),
                $request->body()
            ));

            return ['ok' => true, 'data' => ['title' => 'parsed']];
        });

        $result = app(ModuleWorkerClient::class)->invoke(
            $identity,
            'content.parse',
            ['url' => 'https://example.test/video'],
            'invoke-request'
        );

        $this->assertSame(['title' => 'parsed'], $result);
    }

    public function test_invoke_rejects_operation_not_declared_by_release(): void
    {
        [$module, $release] = $this->createExternalModule('scoped_worker', 'installed', 'active');
        $identity = new ModuleIdentity(
            (string) $module->name,
            (int) $release->id,
            'community',
            ['content:parse'],
            'scoped-request'
        );
        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未声明');

        app(ModuleWorkerClient::class)->invoke($identity, 'admin.shell', [], 'scoped-request');
    }

    public function test_worker_response_with_bad_signature_fails_closed(): void
    {
        Http::fake(['*' => Http::response(json_encode([
            'status' => 'ok',
            'protocol_version' => '1.0',
            'modules' => [],
        ], JSON_THROW_ON_ERROR), 200, [
            'X-Module-Worker-Key-Id' => 'worker-v1',
            'X-Module-Worker-Signature' => str_repeat('0', 64),
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('签名');

        app(ModuleWorkerClient::class)->health();
    }

    public function test_worker_timeout_is_reported_without_exposing_transport_details(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('provider-secret-internal-detail');
        });

        try {
            app(ModuleWorkerClient::class)->health();
            $this->fail('Expected Worker timeout to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('连接', $exception->getMessage());
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
        }
    }

    public function test_worker_response_larger_than_configured_limit_is_rejected(): void
    {
        Config::set('modules.worker.max_response_bytes', 32);
        Http::fake(['*' => Http::response(str_repeat('x', 33), 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('响应大小');

        app(ModuleWorkerClient::class)->health();
    }

    public function test_worker_url_must_be_an_origin_without_credentials_or_path(): void
    {
        Config::set('modules.worker.url', 'https://user:secret@worker.example.test/prefix?token=secret');
        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('根地址');

        app(ModuleWorkerClient::class)->health();
    }

    public function test_worker_eligibility_rejects_incompatible_protocol_and_release_hash(): void
    {
        [$module] = $this->createExternalModule('eligibility_module', 'installed', 'active');
        $this->fakeSignedWorker(static fn (): array => [
            'status' => 'ok',
            'protocol_version' => '2.0',
            'modules' => [
                'eligibility_module' => [
                    'release_hash' => str_repeat('f', 64),
                    'operations' => ['content.parse'],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('协议');

        app(ModuleWorkerEligibility::class)->assertEligible($module);
    }

    public function test_worker_eligibility_rejects_release_hash_mismatch(): void
    {
        [$module] = $this->createExternalModule('hash_mismatch', 'installed', 'active');
        $this->fakeHealthyModule('hash_mismatch', str_repeat('f', 64), ['content.parse']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('哈希');

        app(ModuleWorkerEligibility::class)->assertEligible($module);
    }

    public function test_worker_health_cache_is_invalidated_when_protocol_changes(): void
    {
        [$module, $release] = $this->createExternalModule('protocol_cache', 'installed', 'active');
        $requests = 0;
        $this->fakeSignedWorker(function () use (&$requests, $release): array {
            $requests++;

            return [
                'status' => 'ok',
                'protocol_version' => (string) config('modules.worker.protocol_version'),
                'modules' => [
                    'protocol_cache' => [
                        'release_hash' => (string) $release->artifact_hash,
                        'operations' => ['content.parse'],
                    ],
                ],
            ];
        });

        app(ModuleWorkerEligibility::class)->assertEligible($module);

        $manifest = $release->manifest_json;
        $manifest['worker']['protocol_version'] = '2.0';
        $release->forceFill(['manifest_json' => $manifest])->save();
        Config::set('modules.worker.protocol_version', '2.0');

        app(ModuleWorkerEligibility::class)->assertEligible($module);

        $this->assertSame(2, $requests);
    }

    public function test_worker_health_cache_is_invalidated_when_active_key_changes(): void
    {
        [$module, $release] = $this->createExternalModule('key_cache', 'installed', 'active');
        $requests = 0;
        $this->fakeSignedWorker(function () use (&$requests, $release): array {
            $requests++;

            return [
                'status' => 'ok',
                'protocol_version' => '1.0',
                'modules' => [
                    'key_cache' => [
                        'release_hash' => (string) $release->artifact_hash,
                        'operations' => ['content.parse'],
                    ],
                ],
            ];
        });

        app(ModuleWorkerEligibility::class)->assertEligible($module);

        Config::set('modules.worker.keys', [
            'worker-v1' => str_repeat('w', 32),
            'worker-v2' => str_repeat('n', 32),
        ]);
        Config::set('modules.worker.active_key_id', 'worker-v2');

        app(ModuleWorkerEligibility::class)->assertEligible($module);

        $this->assertSame(2, $requests);
    }

    public function test_production_external_module_uses_worker_without_executing_module_php(): void
    {
        [$module, $release] = $this->createExternalModule('worker_module', 'approved', 'approved', true);
        $this->app['env'] = 'production';
        $this->fakeHealthyModule('worker_module', (string) $release->artifact_hash, ['content.parse']);

        app(ModuleInstaller::class)->install('worker_module', 7);
        app(ModuleInstaller::class)->enable('worker_module', 7);

        $this->assertSame('enabled', $module->refresh()->status);
        $this->assertSame('active', $release->refresh()->status);
        $this->assertArrayNotHasKey('worker_module', app(ModuleManager::class)->enabled());
        $this->assertFalse(class_exists('Tests\\WorkerModule\\Controllers\\ForbiddenController', false));
    }

    public function test_activation_uses_target_release_trust_level_to_prevent_in_process_php(): void
    {
        [$module, $release] = $this->createExternalModule('target_trust', 'approved', 'approved', true);
        $module->forceFill(['type' => 'private', 'trust_level' => 'private'])->save();
        $this->app['env'] = 'production';
        $this->fakeHealthyModule('target_trust', (string) $release->artifact_hash, ['content.parse']);

        app(ModuleInstaller::class)->install('target_trust', 7);

        $this->assertSame('installed', $module->refresh()->status);
        $this->assertSame('community', $module->trust_level);
        $this->assertFalse(class_exists('Tests\\TargetTrust\\Controllers\\ForbiddenController', false));
    }

    public function test_enable_uses_active_release_trust_level_to_prevent_in_process_php(): void
    {
        [$module, $release] = $this->createExternalModule('enable_target_trust', 'disabled', 'active', true);
        $module->forceFill(['type' => 'private', 'trust_level' => 'private'])->save();
        $this->app['env'] = 'production';
        $this->fakeHealthyModule('enable_target_trust', (string) $release->artifact_hash, ['content.parse']);

        app(ModuleInstaller::class)->enable('enable_target_trust', 7);

        $this->assertSame('enabled', $module->refresh()->status);
        $this->assertFalse(class_exists('Tests\\EnableTargetTrust\\Controllers\\ForbiddenController', false));
    }

    public function test_production_external_module_activation_fails_before_module_php_when_worker_is_incompatible(): void
    {
        [$module, $release] = $this->createExternalModule('blocked_worker', 'approved', 'approved', true);
        $this->app['env'] = 'production';
        $this->fakeSignedWorker(static fn (): array => [
            'status' => 'ok',
            'protocol_version' => '2.0',
            'modules' => [],
        ]);

        try {
            app(ModuleInstaller::class)->install('blocked_worker', 7);
            $this->fail('Expected incompatible Worker to block release activation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('协议', $exception->getMessage());
            $this->assertStringNotContainsString('external module PHP executed', $exception->getMessage());
        }

        $this->assertSame('approved', $module->refresh()->status);
        $this->assertSame('approved', $release->refresh()->status);
    }

    public function test_production_external_module_rollback_attests_target_without_executing_php(): void
    {
        [$module, $current] = $this->createExternalModule('rollback_worker', 'enabled', 'active', true);
        $target = $this->createPreviousRelease($module);
        $module->forceFill(['type' => 'private', 'trust_level' => 'private'])->save();
        $this->app['env'] = 'production';
        $this->fakeHealthyModule('rollback_worker', (string) $target->artifact_hash, ['content.parse']);

        app(ModuleRollbacker::class)->rollback('rollback_worker', 7);

        $this->assertSame($target->id, $module->refresh()->active_release_id);
        $this->assertSame('community', $module->trust_level);
        $this->assertSame('active', $target->refresh()->status);
        $this->assertSame('superseded', $current->refresh()->status);
        $this->assertFalse(class_exists('Tests\\RollbackWorker\\Controllers\\ForbiddenController', false));
    }

    public function test_production_external_module_reinstall_does_not_execute_php(): void
    {
        [$module, $release] = $this->createExternalModule('reinstall_worker', 'installed', 'active', true);
        $this->app['env'] = 'production';
        $this->fakeSignedWorker(function () use ($release): array {
            $this->assertSame(0, DB::connection()->transactionLevel());

            return [
                'status' => 'ok',
                'protocol_version' => '1.0',
                'modules' => [
                    'reinstall_worker' => [
                        'release_hash' => (string) $release->artifact_hash,
                        'operations' => ['content.parse'],
                    ],
                ],
            ];
        });

        app(ModuleInstaller::class)->install('reinstall_worker', 7);

        $this->assertSame('installed', $module->refresh()->status);
        $this->assertFalse(class_exists('Tests\\ReinstallWorker\\Controllers\\ForbiddenController', false));
    }

    public function test_runtime_distinguishes_worker_execution_from_in_process_loading(): void
    {
        [$module, $release] = $this->createExternalModule('runtime_worker', 'enabled', 'active');
        $this->app['env'] = 'production';
        $this->fakeHealthyModule('runtime_worker', (string) $release->artifact_hash, ['content.parse']);
        $eligibility = app(ModuleRuntimeEligibility::class);

        $this->assertSame($module->id, $eligibility->assertExecutable($module)->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('主进程');
        $eligibility->assertEligible($module);
    }

    public function test_external_worker_requires_explicit_manifest_operations(): void
    {
        [$module, $release] = $this->createExternalModule('undeclared_worker', 'installed', 'active');
        $manifest = $release->manifest_json;
        $manifest['worker']['operations'] = [];
        $release->forceFill(['manifest_json' => $manifest])->save();
        $this->fakeHealthyModule('undeclared_worker', (string) $release->artifact_hash, []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('操作');

        app(ModuleWorkerEligibility::class)->assertEligible($module);
    }

    public function test_worker_eligibility_uses_target_release_contract_during_upgrade(): void
    {
        [$module, $release] = $this->createExternalModule('upgrade_contract', 'approved', 'approved');
        $currentManifest = $module->config_json;
        $currentManifest['worker']['operations'] = ['old.operation'];
        $module->forceFill(['config_json' => $currentManifest])->save();
        $targetManifest = $release->manifest_json;
        $targetManifest['worker']['operations'] = ['new.operation'];
        $release->forceFill(['manifest_json' => $targetManifest])->save();
        $this->fakeHealthyModule('upgrade_contract', (string) $release->artifact_hash, ['new.operation']);

        $this->assertSame('ok', app(ModuleWorkerEligibility::class)->assertEligible($module)['status']);
    }

    public function test_worker_runtime_uses_active_release_while_upgrade_is_pending_review(): void
    {
        [$module, $active] = $this->createExternalModule('active_with_pending', 'installed', 'active');
        $pendingManifest = $active->manifest_json;
        $pendingManifest['version'] = '1.1.0';
        $pending = SystemModuleRelease::query()->create([
            'module' => $module->name,
            'version' => '1.1.0',
            'source_type' => 'zip',
            'trust_level' => 'community',
            'artifact_path' => $active->artifact_path,
            'artifact_hash' => str_repeat('f', 64),
            'manifest_json' => $pendingManifest,
            'status' => 'pending_review',
        ]);
        $module->forceFill(['pending_release_id' => $pending->id])->save();
        $this->fakeHealthyModule('active_with_pending', (string) $active->artifact_hash, ['content.parse']);

        $this->assertSame('ok', app(ModuleWorkerEligibility::class)->assertEligible($module)['status']);
    }

    /**
     * @return array{SystemModule, SystemModuleRelease}
     */
    private function createExternalModule(
        string $name,
        string $moduleStatus,
        string $releaseStatus,
        bool $withForbiddenPhp = false
    ): array {
        $path = $this->root.DIRECTORY_SEPARATOR.Str::studly($name);
        mkdir($path.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Controllers', 0777, true);
        mkdir($path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0777, true);
        $manifest = [
            'schema_version' => '1.0',
            'name' => $name,
            'title' => Str::headline($name),
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'community',
            'core_version' => '^8.0',
            'php' => '>=8.3',
            'namespace' => 'Tests\\'.Str::studly($name),
            'admin_prefix' => $name,
            'controllers' => 'src/Controllers',
            'views' => 'resources/views',
            'assets' => 'assets',
            'migrations' => 'database/migrations',
            'permissions' => [],
            'external_domains' => [],
            'dependencies' => [],
            'conflicts' => [],
            'api' => ['abilities' => [], 'quotas' => []],
            'worker' => [
                'protocol_version' => '1.0',
                'operations' => ['content.parse'],
            ],
            'menus' => [],
        ];
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        if ($withForbiddenPhp) {
            file_put_contents(
                $path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_07_15_000001_forbidden.php',
                "<?php\nthrow new \\RuntimeException('external module PHP executed');\n"
            );
            file_put_contents(
                $path.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'ForbiddenController.php',
                "<?php\nnamespace Tests\\".Str::studly($name)."\\Controllers;\nfinal class ForbiddenController {}\n"
            );
        }

        $hash = app(ModuleArtifactHasher::class)->hashDirectory($path);
        $release = SystemModuleRelease::query()->create([
            'module' => $name,
            'version' => '1.0.0',
            'source_type' => 'zip',
            'trust_level' => 'community',
            'artifact_path' => $path,
            'artifact_hash' => $hash,
            'manifest_json' => $manifest,
            'status' => $releaseStatus,
            'reviewed_by' => 7,
            'reviewed_at' => now(),
        ]);
        $release->signature_hash = app(ModuleReleaseSigner::class)->sign($release);
        $release->save();
        $module = SystemModule::query()->create([
            'name' => $name,
            'title' => Str::headline($name),
            'vendor' => 'tests',
            'version' => '1.0.0',
            'type' => 'community',
            'trust_level' => 'community',
            'status' => $moduleStatus,
            'path' => $path,
            'namespace' => 'Tests\\'.Str::studly($name),
            'admin_prefix' => $name,
            'signature_hash' => $release->signature_hash,
            'active_release_id' => $releaseStatus === 'active' ? $release->id : null,
            'pending_release_id' => $releaseStatus === 'approved' ? $release->id : null,
            'config_json' => $manifest,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [$module, $release];
    }

    /**
     * @param  callable(Request): array<string, mixed>  $payload
     */
    private function fakeSignedWorker(callable $payload): void
    {
        Http::fake(function (Request $request) use ($payload) {
            $responsePayload = $payload($request);
            $body = json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $requestId = $this->header($request, 'X-Module-Request-Id');
            $headers = app(ModuleWorkerRequestSigner::class)->responseHeaders(
                $path,
                200,
                $requestId,
                $body
            );

            return Http::response($body, 200, $headers);
        });
    }

    private function createPreviousRelease(SystemModule $module): SystemModuleRelease
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'RollbackWorkerPrevious';
        mkdir($path.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Controllers', 0777, true);
        mkdir($path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0777, true);
        $manifest = is_array($module->config_json) ? $module->config_json : [];
        $manifest['version'] = '0.9.0';
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'ForbiddenController.php',
            "<?php\nnamespace Tests\\RollbackWorker\\Controllers;\nfinal class ForbiddenController {}\n"
        );
        $release = SystemModuleRelease::query()->create([
            'module' => $module->name,
            'version' => '0.9.0',
            'source_type' => 'zip',
            'trust_level' => 'community',
            'artifact_path' => $path,
            'artifact_hash' => app(ModuleArtifactHasher::class)->hashDirectory($path),
            'manifest_json' => $manifest,
            'status' => 'superseded',
            'reviewed_by' => 7,
            'reviewed_at' => now()->subDay(),
            'activated_at' => now()->subDay(),
        ]);
        $release->signature_hash = app(ModuleReleaseSigner::class)->sign($release);
        $release->save();

        return $release;
    }

    /**
     * @param  list<string>  $operations
     */
    private function fakeHealthyModule(string $module, string $releaseHash, array $operations): void
    {
        $this->fakeSignedWorker(static fn (): array => [
            'status' => 'ok',
            'protocol_version' => '1.0',
            'modules' => [
                $module => [
                    'release_hash' => $releaseHash,
                    'operations' => $operations,
                ],
            ],
        ]);
    }

    private function header(Request $request, string $name): string
    {
        $values = $request->header($name);

        return is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
    }

    private function deletePath(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child)) {
                $this->deletePath($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
