<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserApiRefreshToken;
use App\Models\UserApiSession;
use App\User\UserApiException;
use App\User\UserApiTokenService;
use App\User\UserAuthService;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class UserApiTokenAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_token_storage_schema_and_module_policy_are_available(): void
    {
        $this->assertTrue(class_exists(\Laravel\Sanctum\Sanctum::class));
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

    private function registeredUser(string $email): UserAccount
    {
        app(UserAuthService::class)->register([
            'email' => $email,
            'password' => 'secret123',
        ], '127.0.0.1');

        return UserAccount::query()->where('email', $email)->firstOrFail();
    }
}
