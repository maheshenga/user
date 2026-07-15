<?php

namespace Tests\Feature\User;

use App\Models\ModuleRegistrationTicket;
use App\Models\SystemModule;
use App\Models\SystemModuleRelease;
use App\Models\UserAccount;
use App\Models\UserApiRefreshToken;
use App\Models\UserApiSession;
use App\Modules\ModuleInstaller;
use App\User\ModuleRegistrationTicketService;
use App\User\UserApiException;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use App\User\UserModuleMembershipService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserApiTokenAuthTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        Config::set('modules.registration_ticket_key', str_repeat('k', 32));
        $manifest = json_decode(
            file_get_contents(base_path('modules/QingyuIpAgent/module.json')) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        SystemModule::query()->create([
            'name' => 'qingyu_ip_agent',
            'title' => '轻语IP智能体',
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

    public function test_token_storage_schema_and_module_policy_are_available(): void
    {
        $this->assertTrue(class_exists(Sanctum::class));
        $this->assertTrue(method_exists(UserAccount::class, 'createToken'));

        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasTable('user_api_sessions'));
        $this->assertTrue(Schema::hasTable('user_api_refresh_tokens'));

        $this->assertTrue(Schema::hasColumns('user_api_sessions', [
            'user_id',
            'module',
            'device_id',
            'device_name',
            'access_token_id',
            'last_ip',
            'last_used_at',
            'revoked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('user_api_refresh_tokens', [
            'session_id',
            'token_hash',
            'expires_at',
            'used_at',
            'revoked_at',
        ]));

        $this->assertSame(15, config('user_api.access_token_minutes'));
        $this->assertSame(30, config('user_api.refresh_token_days'));
        $this->assertSame([
            'profile:read',
            'vip:read',
            'invite:read',
            'balance:read',
            'activation:redeem',
            'content:parse',
            'content:rewrite',
            'module:qingyu_ip_agent',
        ], config('user_api.modules.qingyu_ip_agent.abilities'));
    }

    public function test_authenticate_returns_user_without_writing_web_session(): void
    {
        $this->assertTrue(method_exists(UserAuthService::class, 'authenticate'));

        $auth = app(UserAuthService::class);
        $auth->register([
            'email' => 'stateless@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $result = $auth->authenticate([
            'account' => 'stateless@example.com',
            'password' => 'secret123',
        ], '127.0.0.2');

        $this->assertSame('stateless@example.com', $result['user']['email']);
        $this->assertNull(session('user'));
    }

    public function test_issue_creates_scoped_access_token_and_hashed_refresh_token(): void
    {
        $this->assertTrue(class_exists(UserApiTokenService::class));

        $user = $this->registeredUser('issue@example.com');

        $tokens = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'device-issue', 'device_name' => 'Test Desktop'],
            '127.0.0.3',
            'Token Feature Test'
        );

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(900, $tokens['expires_in']);
        $this->assertSame(2592000, $tokens['refresh_expires_in']);
        $this->assertNotSame('', $tokens['access_token']);
        $this->assertNotSame('', $tokens['refresh_token']);

        $accessToken = PersonalAccessToken::findToken($tokens['access_token']);
        $this->assertNotNull($accessToken);
        $this->assertSame(config('user_api.modules.qingyu_ip_agent.abilities'), $accessToken->abilities);
        $this->assertNotSame($tokens['access_token'], $accessToken->token);

        $session = UserApiSession::query()->firstOrFail();
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame('qingyu_ip_agent', $session->module);
        $this->assertSame('device-issue', $session->device_id);
        $this->assertSame($accessToken->id, $session->access_token_id);

        $refresh = UserApiRefreshToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $tokens['refresh_token']), $refresh->token_hash);
        $this->assertNotSame($tokens['refresh_token'], $refresh->token_hash);
    }

    public function test_core_attributed_user_with_qingyu_membership_can_receive_qingyu_tokens(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'core-member@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $user = UserAccount::query()->where('email', 'core-member@example.com')->firstOrFail();
        app(UserModuleMembershipService::class)->grant(
            (int) $user->id,
            'qingyu_ip_agent',
            'module_join'
        );

        $tokens = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'core-member-device'],
            '127.0.0.3',
            'Core Member Test'
        );

        $this->assertSame('core', $user->refresh()->source_module);
        $this->assertSame('Bearer', $tokens['token_type']);
    }

    public function test_qingyu_attribution_without_membership_cannot_receive_qingyu_tokens(): void
    {
        $user = UserAccount::query()->create([
            'email' => 'attribution-only@example.com',
            'password' => 'testing-password',
            'nickname' => 'Attribution Only',
            'status' => 'active',
            'source_module' => 'qingyu_ip_agent',
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        try {
            app(UserApiTokenService::class)->issue(
                $user,
                'qingyu_ip_agent',
                ['device_id' => 'attribution-only-device'],
                '127.0.0.3',
                'Attribution Only Test'
            );
            $this->fail('Attribution alone must not authorize module token issue.');
        } catch (UserApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
            $this->assertSame('module_membership_required', $exception->errorCode());
        }

        $this->assertDatabaseCount('user_api_sessions', 0);
    }

    public function test_refresh_rotation_consumes_old_refresh_and_replaces_access_token(): void
    {
        $this->assertTrue(class_exists(UserApiTokenService::class));

        $user = $this->registeredUser('rotate@example.com');
        $service = app(UserApiTokenService::class);
        $issued = $service->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'device-rotate', 'device_name' => 'Rotate Desktop'],
            '127.0.0.4',
            'Token Feature Test'
        );
        $oldAccess = PersonalAccessToken::findToken($issued['access_token']);
        $oldRefresh = UserApiRefreshToken::query()->firstOrFail();

        $rotated = $service->rotate($issued['refresh_token'], '127.0.0.5', 'Token Refresh Test');

        $this->assertNotSame($issued['access_token'], $rotated['access_token']);
        $this->assertNotSame($issued['refresh_token'], $rotated['refresh_token']);
        $this->assertNull(PersonalAccessToken::query()->find($oldAccess->id));
        $this->assertNotNull($oldRefresh->fresh()->used_at);
        $this->assertSame(2, UserApiRefreshToken::query()->count());
        $this->assertSame(1, UserApiRefreshToken::query()->whereNull('used_at')->whereNull('revoked_at')->count());
    }

    public function test_reusing_consumed_refresh_token_revokes_the_device_session(): void
    {
        $this->assertTrue(class_exists(UserApiTokenService::class));

        $user = $this->registeredUser('reuse@example.com');
        $service = app(UserApiTokenService::class);
        $issued = $service->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'device-reuse', 'device_name' => 'Reuse Desktop'],
            '127.0.0.6',
            'Token Feature Test'
        );
        $rotated = $service->rotate($issued['refresh_token'], '127.0.0.7', 'Token Refresh Test');
        $rotatedAccess = PersonalAccessToken::findToken($rotated['access_token']);

        try {
            $service->rotate($issued['refresh_token'], '127.0.0.8', 'Replay Test');
            $this->fail('Expected refresh token replay to fail.');
        } catch (UserApiException $exception) {
            $this->assertSame(401, $exception->httpStatus());
            $this->assertSame('refresh_token_reused', $exception->errorCode());
        }

        $this->assertNotNull(UserApiSession::query()->firstOrFail()->revoked_at);
        $this->assertNull(PersonalAccessToken::query()->find($rotatedAccess->id));
        $this->assertSame(0, UserApiRefreshToken::query()->whereNull('revoked_at')->count());
    }

    public function test_disabled_user_cannot_rotate_refresh_token(): void
    {
        $this->assertTrue(class_exists(UserApiTokenService::class));

        $user = $this->registeredUser('disabled-refresh@example.com');
        $service = app(UserApiTokenService::class);
        $issued = $service->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'device-disabled', 'device_name' => 'Disabled Desktop'],
            '127.0.0.9',
            'Token Feature Test'
        );
        $user->update(['status' => 'disabled']);

        $this->expectException(UserApiException::class);
        $this->expectExceptionMessage('账号当前不可登录。');

        $service->rotate($issued['refresh_token'], '127.0.0.10', 'Disabled Test');
    }

    public function test_revoke_deactivates_the_current_device_session(): void
    {
        $this->assertTrue(class_exists(UserApiTokenService::class));

        $user = $this->registeredUser('logout@example.com');
        $service = app(UserApiTokenService::class);
        $issued = $service->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'device-logout', 'device_name' => 'Logout Desktop'],
            '127.0.0.11',
            'Token Feature Test'
        );
        $access = PersonalAccessToken::findToken($issued['access_token']);

        $service->revoke($user, $access->id);

        $this->assertNotNull(UserApiSession::query()->firstOrFail()->revoked_at);
        $this->assertNull(PersonalAccessToken::query()->find($access->id));
        $this->assertSame(0, UserApiRefreshToken::query()->whereNull('revoked_at')->count());
    }

    public function test_disabled_module_cannot_issue_tokens(): void
    {
        $user = $this->registeredUser('module-disabled-issue@example.com');
        SystemModule::query()->where('name', 'qingyu_ip_agent')->update(['status' => 'disabled']);

        try {
            app(UserApiTokenService::class)->issue(
                $user,
                'qingyu_ip_agent',
                ['device_id' => 'disabled-module-device'],
                '127.0.0.13',
                'Disabled Module Test'
            );
            $this->fail('Expected disabled module token issue to fail.');
        } catch (UserApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
            $this->assertSame('module_unavailable', $exception->errorCode());
        }
    }

    public function test_module_with_invalid_active_release_cannot_issue_tokens(): void
    {
        $user = $this->registeredUser('invalid-release-issue@example.com');
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $release = SystemModuleRelease::query()->create([
            'module' => 'qingyu_ip_agent',
            'version' => (string) $module->version,
            'source_type' => 'local',
            'trust_level' => 'private',
            'artifact_path' => (string) $module->path,
            'artifact_hash' => str_repeat('0', 64),
            'signature_hash' => str_repeat('f', 64),
            'manifest_json' => $module->config_json,
            'status' => 'active',
        ]);
        $module->update(['active_release_id' => $release->id]);

        try {
            app(UserApiTokenService::class)->issue(
                $user,
                'qingyu_ip_agent',
                ['device_id' => 'invalid-release-device'],
                '127.0.0.31',
                'Invalid Release Test'
            );
            $this->fail('Expected an invalid active release to block token issue.');
        } catch (UserApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
            $this->assertSame('module_unavailable', $exception->errorCode());
        }

        $this->assertDatabaseCount('user_api_sessions', 0);
    }

    public function test_disabled_module_cannot_refresh_and_revokes_the_session(): void
    {
        $user = $this->registeredUser('module-disabled-refresh@example.com');
        $service = app(UserApiTokenService::class);
        $issued = $service->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'disabled-refresh-device'],
            '127.0.0.14',
            'Disabled Refresh Test'
        );
        SystemModule::query()->where('name', 'qingyu_ip_agent')->update(['status' => 'disabled']);

        try {
            $service->rotate($issued['refresh_token'], '127.0.0.15', 'Disabled Refresh Test');
            $this->fail('Expected disabled module refresh to fail.');
        } catch (UserApiException $exception) {
            $this->assertSame('module_unavailable', $exception->errorCode());
        }

        $this->assertNotNull(UserApiSession::query()->firstOrFail()->revoked_at);
        $this->assertNull(PersonalAccessToken::findToken($issued['access_token']));
    }

    public function test_disabled_module_token_is_rejected_by_protected_routes(): void
    {
        $user = $this->registeredUser('module-disabled-route@example.com');
        $issued = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'disabled-route-device'],
            '127.0.0.16',
            'Disabled Route Test'
        );
        SystemModule::query()->where('name', 'qingyu_ip_agent')->update(['status' => 'disabled']);

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/auth/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_unavailable');

        $this->assertNull(PersonalAccessToken::findToken($issued['access_token']));
    }

    public function test_disabling_module_revokes_all_module_sessions(): void
    {
        $user = $this->registeredUser('module-disable-lifecycle@example.com');
        $issued = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'module-disable-lifecycle-device'],
            '127.0.0.17',
            'Module Disable Lifecycle Test'
        );

        app(ModuleInstaller::class)->disable('qingyu_ip_agent', 1);

        $this->assertNotNull(UserApiSession::query()->firstOrFail()->revoked_at);
        $this->assertNull(PersonalAccessToken::findToken($issued['access_token']));
    }

    public function test_api_registration_is_csrf_free_and_returns_token_bundle(): void
    {
        $response = $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'api-register@example.com',
            'password' => 'secret123',
            'device_id' => 'api-register-device',
            'device_name' => 'API Register Desktop',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user.email', 'api-register@example.com')
            ->assertJsonPath('data.user.source_module', 'qingyu_ip_agent')
            ->assertJsonPath('data.tokens.token_type', 'Bearer');

        $this->assertIsString($response->json('data.tokens.access_token'));
        $this->assertIsString($response->json('data.tokens.refresh_token'));
        $response->assertSessionMissing('user');
    }

    public function test_generic_api_registration_requires_a_signed_ticket(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'unsigned-register@example.com',
            'password' => 'secret123',
            'module' => 'qingyu_ip_agent',
            'device_id' => 'unsigned-register-device',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseMissing('user_account', ['email' => 'unsigned-register@example.com']);
    }

    public function test_route_bound_registration_creates_membership_and_ignores_payload_module(): void
    {
        $response = $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'route-register@example.com',
            'password' => 'secret123',
            'module' => 'core',
            'device_id' => 'route-register-device',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'route-register@example.com')
            ->assertJsonPath('data.user.source_module', 'qingyu_ip_agent')
            ->assertJsonPath('data.tokens.token_type', 'Bearer');
        $userId = (int) $response->json('data.user.id');
        $this->assertDatabaseHas('user_module_membership', [
            'user_id' => $userId,
            'module' => 'qingyu_ip_agent',
            'status' => 'active',
            'join_source' => 'registration',
        ]);
    }

    public function test_route_bound_ticket_issue_and_generic_registration_are_single_use(): void
    {
        $issued = $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/registration-ticket', [
            'invite_code' => null,
            'campaign' => 'desktop-launch',
            'expires_in' => 300,
        ])->assertCreated();
        $ticket = $issued->json('data.ticket');
        $this->assertIsString($ticket);

        $payload = [
            'email' => 'ticket-register@example.com',
            'password' => 'secret123',
            'registration_ticket' => $ticket,
            'device_id' => 'ticket-register-device',
        ];
        $this->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.source_module', 'qingyu_ip_agent');

        $this->postJson('/api/v1/auth/register', array_replace($payload, ['email' => 'ticket-replay@example.com']))
            ->assertConflict()
            ->assertJsonPath('code', 'registration_ticket_replayed');
    }

    public function test_ticket_consumption_account_and_membership_roll_back_when_token_issue_fails(): void
    {
        $ticket = app(ModuleRegistrationTicketService::class)->issue(
            'qingyu_ip_agent',
            [],
            now()->addMinutes(5)
        );
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $manifest = $module->config_json;
        $manifest['api']['abilities'] = [];
        $module->update(['config_json' => $manifest]);

        $this->postJson('/api/v1/auth/register', [
            'email' => 'ticket-token-failure@example.com',
            'password' => 'secret123',
            'registration_ticket' => $ticket,
            'device_id' => 'ticket-token-failure-device',
        ])->assertForbidden()
            ->assertJsonPath('code', 'module_not_allowed');

        $this->assertDatabaseMissing('user_account', ['email' => 'ticket-token-failure@example.com']);
        $this->assertDatabaseCount('user_module_membership', 0);
        $this->assertNull(ModuleRegistrationTicket::query()->firstOrFail()->consumed_at);
    }

    public function test_route_bound_login_joins_a_second_module_without_changing_attribution(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'route-join@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $user = UserAccount::query()->where('email', 'route-join@example.com')->firstOrFail();

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/login', [
            'account' => 'route-join@example.com',
            'password' => 'secret123',
            'module' => 'core',
            'device_id' => 'route-join-device',
        ])->assertOk()
            ->assertJsonPath('data.tokens.token_type', 'Bearer');

        $this->assertSame('core', $user->refresh()->source_module);
        $this->assertDatabaseHas('user_module_membership', [
            'user_id' => $user->id,
            'module' => 'qingyu_ip_agent',
            'status' => 'active',
            'join_source' => 'route_login',
        ]);
    }

    public function test_route_bound_login_rolls_back_membership_when_token_issue_fails(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'route-join-rollback@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $user = UserAccount::query()->where('email', 'route-join-rollback@example.com')->firstOrFail();
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $manifest = $module->config_json;
        $manifest['api']['abilities'] = [];
        $module->update(['config_json' => $manifest]);

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/login', [
            'account' => 'route-join-rollback@example.com',
            'password' => 'secret123',
            'device_id' => 'route-join-rollback-device',
        ])->assertForbidden()
            ->assertJsonPath('code', 'module_not_allowed');

        $this->assertDatabaseMissing('user_module_membership', [
            'user_id' => $user->id,
            'module' => 'qingyu_ip_agent',
        ]);
        $this->assertNull($user->refresh()->last_login_at);
        $this->assertDatabaseCount('user_login_log', 0);
    }

    public function test_route_bound_login_does_not_reactivate_revoked_membership(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'route-revoked@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $user = UserAccount::query()->where('email', 'route-revoked@example.com')->firstOrFail();
        $memberships = app(UserModuleMembershipService::class);
        $memberships->grant((int) $user->id, 'qingyu_ip_agent', 'admin_grant', 1);
        $memberships->revoke((int) $user->id, 'qingyu_ip_agent');

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/login', [
            'account' => 'route-revoked@example.com',
            'password' => 'secret123',
            'device_id' => 'route-revoked-device',
        ])->assertForbidden()
            ->assertJsonPath('code', 'module_membership_revoked');

        $this->assertDatabaseHas('user_module_membership', [
            'user_id' => $user->id,
            'module' => 'qingyu_ip_agent',
            'status' => 'revoked',
        ]);
        $this->assertDatabaseCount('user_api_sessions', 0);
    }

    public function test_route_bound_login_preserves_failed_login_log_for_rate_limiting(): void
    {
        app(UserAuthService::class)->register([
            'email' => 'route-login-failure@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/login', [
            'account' => 'route-login-failure@example.com',
            'password' => 'wrong-password',
            'device_id' => 'route-login-failure-device',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');

        $this->assertDatabaseHas('user_login_log', [
            'account' => 'route-login-failure@example.com',
            'login_type' => 'email',
            'result' => 'failed',
        ]);
    }

    public function test_api_registration_does_not_create_account_for_disabled_module(): void
    {
        SystemModule::query()->where('name', 'qingyu_ip_agent')->update(['status' => 'disabled']);

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'disabled-module-orphan@example.com',
            'password' => 'secret123',
            'device_id' => 'disabled-module-orphan-device',
        ])->assertForbidden()
            ->assertJsonPath('code', 'module_unavailable');

        $this->assertDatabaseMissing('user_account', ['email' => 'disabled-module-orphan@example.com']);
    }

    public function test_api_registration_rolls_back_account_when_token_issue_fails(): void
    {
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $manifest = $module->config_json;
        $manifest['api']['abilities'] = [];
        $module->update(['config_json' => $manifest]);

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'token-failure-orphan@example.com',
            'password' => 'secret123',
            'device_id' => 'token-failure-orphan-device',
        ])->assertForbidden()
            ->assertJsonPath('code', 'module_not_allowed');

        $this->assertDatabaseMissing('user_account', ['email' => 'token-failure-orphan@example.com']);
        $this->assertDatabaseCount('user_module_membership', 0);
        $this->assertDatabaseCount('user_api_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_login_and_profile_use_bearer_auth_without_web_session(): void
    {
        $this->registeredUser('api-login@example.com');

        $login = $this->postJson('/api/v1/auth/login', [
            'account' => 'api-login@example.com',
            'password' => 'secret123',
            'module' => 'qingyu_ip_agent',
            'device_id' => 'api-login-device',
            'device_name' => 'API Login Desktop',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.userInfo.email', 'api-login@example.com');
        $login->assertSessionMissing('user');

        $this->getJson('/api/v1/auth/profile')->assertUnauthorized();

        $this->withToken($login->json('data.tokens.access_token'))
            ->getJson('/api/v1/auth/profile')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'api-login@example.com')
            ->assertJsonPath('data.userInfo.is_vip', 0);
    }

    public function test_api_refresh_rotates_tokens_and_rejects_old_access_token(): void
    {
        $registration = $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'api-refresh@example.com',
            'password' => 'secret123',
            'device_id' => 'api-refresh-device',
        ])->assertCreated();
        $oldAccess = $registration->json('data.tokens.access_token');
        $oldRefresh = $registration->json('data.tokens.refresh_token');

        $refresh = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ]);

        $refresh->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tokens.token_type', 'Bearer');
        $this->assertNotSame($oldAccess, $refresh->json('data.tokens.access_token'));
        $this->assertNotSame($oldRefresh, $refresh->json('data.tokens.refresh_token'));

        $this->withToken($oldAccess)->getJson('/api/v1/auth/profile')->assertUnauthorized();
        $this->withToken($refresh->json('data.tokens.access_token'))
            ->getJson('/api/v1/auth/profile')
            ->assertOk();
    }

    public function test_api_logout_revokes_the_current_access_and_refresh_session(): void
    {
        $registration = $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', [
            'email' => 'api-logout@example.com',
            'password' => 'secret123',
            'device_id' => 'api-logout-device',
        ])->assertCreated();
        $access = $registration->json('data.tokens.access_token');
        $refresh = $registration->json('data.tokens.refresh_token');
        $accessModel = PersonalAccessToken::findToken($access);

        $this->withToken($access)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);

        $this->assertNull(PersonalAccessToken::query()->find($accessModel->id));
        $this->app['auth']->forgetGuards();
        $this->withToken($access)->getJson('/api/v1/auth/profile')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertUnauthorized();
    }

    public function test_bearer_me_endpoints_expose_vip_invitation_balance_and_ledger_reads(): void
    {
        $user = $this->registeredUser('api-me@example.com');
        $issued = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'api-me-device'],
            '127.0.0.18',
            'API Me Test'
        );

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me/vip')
            ->assertOk()
            ->assertJsonPath('data.active', false);
        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me/invitations')
            ->assertOk()
            ->assertJsonPath('data.direct_count', 0);
        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me/balance')
            ->assertOk()
            ->assertJsonPath('data.available_balance', '0.00');
        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me/ledger')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_gateway_capability_revocation_takes_effect_for_existing_token(): void
    {
        $user = $this->registeredUser('capability-revoked@example.com');
        $issued = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'capability-revoked-device'],
            '127.0.0.32',
            'Capability Revocation Test'
        );
        $module = SystemModule::query()->where('name', 'qingyu_ip_agent')->firstOrFail();
        $manifest = $module->config_json;
        $manifest['permissions'] = array_values(array_diff($manifest['permissions'] ?? [], ['balance:read']));
        $module->update(['config_json' => $manifest]);

        $this->withToken($issued['access_token'])
            ->getJson('/api/v1/me/balance')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_capability_denied');
    }

    public function test_module_host_gateway_contracts_are_bound(): void
    {
        $bindings = [
            'App\\Contracts\\Modules\\MemberGateway' => 'App\\Modules\\Host\\HostMemberGateway',
            'App\\Contracts\\Modules\\InvitationGateway' => 'App\\Modules\\Host\\HostInvitationGateway',
            'App\\Contracts\\Modules\\VipGateway' => 'App\\Modules\\Host\\HostVipGateway',
            'App\\Contracts\\Modules\\ActivationCodeGateway' => 'App\\Modules\\Host\\HostActivationCodeGateway',
            'App\\Contracts\\Modules\\BalanceGateway' => 'App\\Modules\\Host\\HostBalanceGateway',
            'App\\Contracts\\Modules\\AffiliateGateway' => 'App\\Modules\\Host\\HostAffiliateGateway',
            'App\\Contracts\\Modules\\AuditGateway' => 'App\\Modules\\Host\\HostAuditGateway',
            'App\\Contracts\\Modules\\NotificationGateway' => 'App\\Modules\\Host\\HostNotificationGateway',
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->assertInstanceOf($implementation, app($contract));
        }
    }

    public function test_api_returns_specific_statuses_for_duplicate_and_bad_credentials(): void
    {
        $payload = [
            'email' => 'api-errors@example.com',
            'password' => 'secret123',
            'device_id' => 'api-errors-device',
        ];
        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', $payload)->assertCreated();

        $this->postJson('/api/v1/auth/modules/qingyu_ip_agent/register', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'account_exists');

        $this->postJson('/api/v1/auth/login', [
            'account' => 'api-errors@example.com',
            'password' => 'wrong-password',
            'module' => 'qingyu_ip_agent',
            'device_id' => 'api-errors-login',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_api_profile_rejects_token_without_profile_scope(): void
    {
        $user = $this->registeredUser('api-scope@example.com');
        $token = $user->createToken('wrong-scope', ['vip:read'], now()->addMinutes(15));

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'ability_denied');
    }

    public function test_disabled_user_is_rejected_and_all_api_sessions_are_revoked(): void
    {
        $user = $this->registeredUser('api-disabled@example.com');
        $tokens = app(UserApiTokenService::class)->issue(
            $user,
            'qingyu_ip_agent',
            ['device_id' => 'api-disabled-device'],
            '127.0.0.12',
            'Disabled API Test'
        );
        $user->update(['status' => 'disabled']);

        $this->withToken($tokens['access_token'])
            ->getJson('/api/v1/auth/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');

        $this->assertNull(PersonalAccessToken::findToken($tokens['access_token']));
        $this->assertNotNull(UserApiSession::query()->firstOrFail()->revoked_at);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized();
    }

    public function test_api_auth_routes_are_rate_limited_and_protected_as_expected(): void
    {
        foreach ([
            ['POST', '/api/v1/auth/register'],
            ['POST', '/api/v1/auth/login'],
            ['POST', '/api/v1/auth/refresh'],
        ] as [$method, $path]) {
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => in_array($method, $route->methods(), true) && '/'.$route->uri() === $path
            );
            $this->assertNotNull($route, "{$path} route must exist.");
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(fn (string $name): bool => str_starts_with($name, 'throttle:')),
                "{$path} must be rate limited."
            );
        }

        $profile = collect(Route::getRoutes())->first(
            fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/auth/profile'
        );
        $this->assertNotNull($profile, '/api/v1/auth/profile route must exist.');
        $this->assertContains('auth:sanctum', $profile->gatherMiddleware());
        $this->assertContains('api.active', $profile->gatherMiddleware());
        $this->assertContains('api.ability:profile:read', $profile->gatherMiddleware());
    }

    public function test_unauthenticated_api_requests_force_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/auth/profile')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    private function registeredUser(string $email): UserAccount
    {
        app(UserAuthService::class)->register([
            'email' => $email,
            'password' => 'secret123',
        ], '127.0.0.1', 'qingyu_ip_agent');

        return UserAccount::query()->where('email', $email)->firstOrFail();
    }
}
