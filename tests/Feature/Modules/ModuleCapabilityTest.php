<?php

namespace Tests\Feature\Modules;

use App\Contracts\Modules\NotificationGateway;
use App\Models\SystemModule;
use App\Models\UserNotificationOutbox;
use App\Modules\ModuleApiException;
use App\Modules\ModuleCapabilityPolicy;
use App\Modules\ModuleExecutionContext;
use Tests\TestCase;

class ModuleCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_capability_policy_rejects_calls_without_a_trusted_context(): void
    {
        try {
            app(ModuleCapabilityPolicy::class)->authorize('balance:read');
            $this->fail('Expected a missing module execution context to be rejected.');
        } catch (ModuleApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
            $this->assertSame('module_context_missing', $exception->errorCode());
        }
    }

    public function test_capability_policy_rejects_missing_capability_and_cross_module_ownership(): void
    {
        $module = $this->module(['vip:read']);
        $context = app(ModuleExecutionContext::class);

        $context->run($module, 'request-capability', function (): void {
            try {
                app(ModuleCapabilityPolicy::class)->authorize('balance:read');
                $this->fail('Expected a missing capability to be rejected.');
            } catch (ModuleApiException $exception) {
                $this->assertSame('module_capability_denied', $exception->errorCode());
            }

            try {
                app(ModuleCapabilityPolicy::class)->authorize('vip:read', 'another_module');
                $this->fail('Expected cross-module ownership to be rejected.');
            } catch (ModuleApiException $exception) {
                $this->assertSame('module_ownership_denied', $exception->errorCode());
            }
        });
    }

    public function test_notification_gateway_derives_module_and_request_identity_from_context(): void
    {
        $module = $this->module(['notification:write']);

        $id = app(ModuleExecutionContext::class)->run(
            $module,
            'request-notification',
            fn (): int => app(NotificationGateway::class)->enqueue(
                null,
                'email',
                'member@example.test',
                'Module notice',
                ['event' => 'ready', 'module' => 'forged', 'request_id' => 'forged']
            )
        );

        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('user_notification_outbox', [
            'id' => $id,
            'type' => 'module:qingyu_ip_agent',
        ]);
        $payload = UserNotificationOutbox::query()->findOrFail($id)->payload_json;
        $this->assertIsArray($payload);
        $this->assertSame('qingyu_ip_agent', $payload['module']);
        $this->assertSame('request-notification', $payload['request_id']);
    }

    public function test_explicit_host_context_has_wildcard_capabilities_and_is_restored(): void
    {
        $context = app(ModuleExecutionContext::class);

        $context->runAsHost(function (): void {
            $identity = app(ModuleCapabilityPolicy::class)->authorize('balance:write', 'any_module');
            $this->assertTrue($identity->isHost());
            $this->assertSame('core', $identity->name);
        });

        $this->expectException(ModuleApiException::class);
        $context->requireCurrent();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function module(array $permissions): SystemModule
    {
        $manifest = json_decode(
            file_get_contents(base_path('modules/QingyuIpAgent/module.json')) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['permissions'] = $permissions;

        return SystemModule::query()->create([
            'name' => 'qingyu_ip_agent',
            'title' => 'Qingyu',
            'vendor' => 'internal',
            'version' => (string) $manifest['version'],
            'type' => 'private',
            'trust_level' => 'private',
            'status' => 'enabled',
            'path' => base_path('modules/QingyuIpAgent'),
            'namespace' => 'Modules\\QingyuIpAgent',
            'admin_prefix' => 'qingyu_ip_agent',
            'config_json' => $manifest,
        ]);
    }
}
