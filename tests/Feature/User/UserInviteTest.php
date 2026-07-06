<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserInviteCode;
use App\Models\UserInviteRelation;
use App\User\UserAuthService;
use App\User\InviteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createSystemConfigTable();
    }

    public function test_invite_phase_2_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_invite_code'));
        $this->assertTrue(Schema::hasTable('user_invite_relation'));
        $this->assertTrue(Schema::hasColumns('user_invite_code', [
            'owner_user_id',
            'code',
            'type',
            'status',
            'max_uses',
            'used_count',
            'expires_at',
            'metadata_json',
            'delete_time',
        ]));
        $this->assertTrue(Schema::hasColumns('user_invite_relation', [
            'user_id',
            'parent_user_id',
            'grandparent_user_id',
            'invite_code_id',
            'level_path',
            'bind_type',
            'status',
            'delete_time',
        ]));

        $this->assertSame(0, UserInviteCode::query()->count());
        $this->assertSame(0, UserInviteRelation::query()->count());
    }

    public function test_registered_user_receives_default_invite_code(): void
    {
        $result = app(UserAuthService::class)->register([
            'mobile' => '13900000001',
            'password' => 'secret123',
        ], '127.0.0.1');

        $this->assertArrayHasKey('invite_code', $result);
        $this->assertSame($result['user']['id'], $result['invite_code']['owner_user_id']);
        $this->assertSame('active', $result['invite_code']['status']);
        $this->assertSame('user', $result['invite_code']['type']);
        $this->assertSame(0, $result['invite_code']['max_uses']);
        $this->assertSame(0, $result['invite_code']['used_count']);

        $this->assertDatabaseHas('user_invite_code', [
            'owner_user_id' => $result['user']['id'],
            'code' => $result['invite_code']['code'],
            'type' => 'user',
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
        ]);
    }

    public function test_registering_with_invite_code_binds_parent_and_grandparent(): void
    {
        $auth = app(UserAuthService::class);

        $parent = $auth->register([
            'mobile' => '13900000002',
            'password' => 'secret123',
        ], '127.0.0.1');

        $child = $auth->register([
            'mobile' => '13900000003',
            'password' => 'secret123',
            'invite_code' => $parent['invite_code']['code'],
        ], '127.0.0.1');

        $grandchild = $auth->register([
            'mobile' => '13900000004',
            'password' => 'secret123',
            'invite_code' => $child['invite_code']['code'],
        ], '127.0.0.1');

        $this->assertDatabaseHas('user_invite_relation', [
            'user_id' => $child['user']['id'],
            'parent_user_id' => $parent['user']['id'],
            'grandparent_user_id' => null,
            'invite_code_id' => $parent['invite_code']['id'],
            'level_path' => (string) $parent['user']['id'],
            'bind_type' => 'register',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('user_invite_relation', [
            'user_id' => $grandchild['user']['id'],
            'parent_user_id' => $child['user']['id'],
            'grandparent_user_id' => $parent['user']['id'],
            'invite_code_id' => $child['invite_code']['id'],
            'level_path' => $parent['user']['id'].'/'.$child['user']['id'],
            'bind_type' => 'register',
            'status' => 'active',
        ]);

        $this->assertSame(1, UserInviteCode::query()->whereKey($parent['invite_code']['id'])->value('used_count'));
        $this->assertSame(1, UserInviteCode::query()->whereKey($child['invite_code']['id'])->value('used_count'));
    }

    public function test_invalid_disabled_expired_and_exhausted_invite_codes_are_rejected(): void
    {
        $auth = app(UserAuthService::class);

        $owner = $auth->register([
            'mobile' => '13900000005',
            'password' => 'secret123',
        ], '127.0.0.1');

        foreach ([
            [fn (): string => 'missing-code', 'Invite code is invalid.'],
            [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['status' => 'disabled']), 'Invite code is not active.'],
            [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['expires_at' => Carbon::now()->subMinute()]), 'Invite code is expired.'],
            [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['max_uses' => 1, 'used_count' => 1]), 'Invite code usage limit reached.'],
        ] as $index => [$codeFactory, $message]) {
            try {
                $auth->register([
                    'mobile' => '1390000001'.$index,
                    'password' => 'secret123',
                    'invite_code' => $codeFactory(),
                ], '127.0.0.1');

                $this->fail("Expected invite code failure: {$message}");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_invite_summary_and_records_return_two_level_counts(): void
    {
        $auth = app(UserAuthService::class);
        $service = app(InviteService::class);

        $parent = $auth->register([
            'email' => 'parent@example.com',
            'password' => 'secret123',
        ], '127.0.0.1');
        $child = $auth->register([
            'email' => 'child@example.com',
            'password' => 'secret123',
            'invite_code' => $parent['invite_code']['code'],
        ], '127.0.0.1');
        $auth->register([
            'email' => 'grandchild@example.com',
            'password' => 'secret123',
            'invite_code' => $child['invite_code']['code'],
        ], '127.0.0.1');

        $summary = $service->inviteSummary($parent['user']['id']);
        $records = $service->inviteRecords($parent['user']['id']);

        $this->assertSame($parent['invite_code']['code'], $summary['invite_code']['code']);
        $this->assertSame(1, $summary['direct_count']);
        $this->assertSame(1, $summary['second_level_count']);
        $this->assertSame($child['user']['id'], $records[0]['user_id']);
        $this->assertSame('child@example.com', $records[0]['email']);
    }

    public function test_register_endpoint_accepts_invite_code_and_user_can_query_invites(): void
    {
        $auth = app(UserAuthService::class);
        $parent = $auth->register([
            'mobile' => '13900000020',
            'password' => 'secret123',
        ], '127.0.0.1');

        $registerResponse = $this->postJson('/user/register', [
            'mobile' => '13900000021',
            'password' => 'secret123',
            'invite_code' => $parent['invite_code']['code'],
        ]);

        $registerResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.invite_relation.parent_user_id', $parent['user']['id']);

        $summaryResponse = $this
            ->withSession(['user' => ['id' => $parent['user']['id']]])
            ->getJson('/user/invite');

        $summaryResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.invite_code.code', $parent['invite_code']['code'])
            ->assertJsonPath('data.direct_count', 1);

        $recordsResponse = $this
            ->withSession(['user' => ['id' => $parent['user']['id']]])
            ->getJson('/user/invite/records');

        $recordsResponse->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.0.mobile', '13900000021');
    }

    public function test_invite_endpoints_require_user_session(): void
    {
        foreach (['/user/invite', '/user/invite/records'] as $uri) {
            $response = $this->getJson($uri);

            $response->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('msg', '请先登录。');
        }
    }

    private function mutatedCode(int $id, array $attributes): string
    {
        UserInviteCode::query()->whereKey($id)->update(array_merge([
            'status' => 'active',
            'max_uses' => 0,
            'used_count' => 0,
            'expires_at' => null,
        ], $attributes));

        return UserInviteCode::query()->whereKey($id)->value('code');
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
