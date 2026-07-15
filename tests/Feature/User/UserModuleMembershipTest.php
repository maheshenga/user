<?php

namespace Tests\Feature\User;

use App\Models\ModuleRegistrationTicket;
use App\Models\UserAccount;
use App\Models\UserModuleMembership;
use App\User\ModuleRegistrationTicketService;
use App\User\UserApiException;
use App\User\UserModuleMembershipService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserModuleMembershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        Config::set('modules.registration_ticket_key', str_repeat('k', 32));
    }

    public function test_membership_and_registration_ticket_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumns('user_module_membership', [
            'id',
            'user_id',
            'module',
            'status',
            'join_source',
            'granted_by',
            'joined_at',
            'revoked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('module_registration_ticket', [
            'id',
            'module',
            'token_hash',
            'claims_json',
            'expires_at',
            'consumed_at',
        ]));
    }

    public function test_one_user_can_join_two_modules_without_changing_attribution(): void
    {
        $this->assertTrue(class_exists(UserModuleMembership::class));
        $this->assertTrue(class_exists(UserModuleMembershipService::class));
        $user = $this->createUser('multi-module@example.com', 'core');
        $memberships = app(UserModuleMembershipService::class);

        $core = $memberships->grant((int) $user->id, 'core', 'registration');
        $qingyu = $memberships->grant((int) $user->id, 'qingyu_ip_agent', 'module_join');
        $again = $memberships->grant((int) $user->id, 'qingyu_ip_agent', 'duplicate_join');

        $this->assertNotSame($core->id, $qingyu->id);
        $this->assertSame($qingyu->id, $again->id);
        $this->assertSame(2, UserModuleMembership::query()->where('user_id', $user->id)->count());
        $this->assertSame('active', $qingyu->refresh()->status);
        $this->assertSame('core', $user->refresh()->source_module);
    }

    public function test_membership_migration_backfills_existing_registration_attribution(): void
    {
        Schema::dropIfExists('module_registration_ticket');
        Schema::dropIfExists('user_module_membership');
        $user = $this->createUser('legacy-attribution@example.com', 'vip_center');

        $migration = require database_path('migrations/2026_07_15_000007_create_user_module_memberships.php');
        $migration->up();

        $this->assertDatabaseHas('user_module_membership', [
            'user_id' => $user->id,
            'module' => 'vip_center',
            'status' => 'active',
            'join_source' => 'attribution_backfill',
        ]);
        $this->assertSame('vip_center', $user->refresh()->source_module);
    }

    public function test_revoked_membership_is_rejected_and_can_be_regranted(): void
    {
        $this->assertTrue(class_exists(UserModuleMembershipService::class));
        $user = $this->createUser('regrant@example.com', 'core');
        $memberships = app(UserModuleMembershipService::class);
        $membership = $memberships->grant((int) $user->id, 'qingyu_ip_agent', 'module_join');

        $memberships->revoke((int) $user->id, 'qingyu_ip_agent');

        try {
            $memberships->assertActive((int) $user->id, 'qingyu_ip_agent');
            $this->fail('A revoked membership should not authorize module access.');
        } catch (UserApiException $exception) {
            $this->assertSame(403, $exception->httpStatus());
            $this->assertSame('module_membership_required', $exception->errorCode());
        }

        $restored = $memberships->grant((int) $user->id, 'qingyu_ip_agent', 'admin_regrant', 9);
        $this->assertSame($membership->id, $restored->id);
        $this->assertSame('active', $restored->status);
        $this->assertNull($restored->revoked_at);
        $this->assertSame(9, $restored->granted_by);
        $this->assertSame('core', $user->refresh()->source_module);
    }

    public function test_registration_ticket_is_signed_hashed_and_single_use(): void
    {
        $this->assertTrue(class_exists(ModuleRegistrationTicket::class));
        $this->assertTrue(class_exists(ModuleRegistrationTicketService::class));
        $tickets = app(ModuleRegistrationTicketService::class);
        $ticket = $tickets->issue(
            'qingyu_ip_agent',
            ['campaign' => 'summer', 'invite_code' => 'INVITE01'],
            now()->addMinutes(5)
        );

        $this->assertStringStartsWith('mrt_', $ticket);
        $record = ModuleRegistrationTicket::query()->firstOrFail();
        $this->assertSame(hash('sha256', $ticket), $record->token_hash);
        $this->assertStringNotContainsString($ticket, json_encode($record->toArray(), JSON_THROW_ON_ERROR));

        $claims = $tickets->consume($ticket);
        $this->assertSame('qingyu_ip_agent', $claims['module']);
        $this->assertSame('summer', $claims['campaign']);
        $this->assertSame('INVITE01', $claims['invite_code']);
        $this->assertNotNull($record->refresh()->consumed_at);

        try {
            $tickets->consume($ticket);
            $this->fail('A consumed registration ticket must not be reusable.');
        } catch (UserApiException $exception) {
            $this->assertSame('registration_ticket_replayed', $exception->errorCode());
        }
    }

    public function test_registration_ticket_rejects_tampering_without_consuming_original(): void
    {
        $this->assertTrue(class_exists(ModuleRegistrationTicketService::class));
        $tickets = app(ModuleRegistrationTicketService::class);
        $ticket = $tickets->issue('qingyu_ip_agent', [], now()->addMinutes(5));
        $tampered = substr($ticket, 0, -1).(str_ends_with($ticket, 'a') ? 'b' : 'a');

        try {
            $tickets->consume($tampered);
            $this->fail('A tampered registration ticket must be rejected.');
        } catch (UserApiException $exception) {
            $this->assertSame('registration_ticket_invalid', $exception->errorCode());
        }

        $this->assertNull(ModuleRegistrationTicket::query()->firstOrFail()->consumed_at);
        $this->assertSame('qingyu_ip_agent', $tickets->consume($ticket)['module']);
    }

    public function test_registration_ticket_expires(): void
    {
        $this->assertTrue(class_exists(ModuleRegistrationTicketService::class));
        $tickets = app(ModuleRegistrationTicketService::class);
        $ticket = $tickets->issue('qingyu_ip_agent', [], now()->addMinute());
        $this->travel(2)->minutes();

        try {
            $tickets->consume($ticket);
            $this->fail('An expired registration ticket must be rejected.');
        } catch (UserApiException $exception) {
            $this->assertSame('registration_ticket_expired', $exception->errorCode());
        }

        $this->assertNull(ModuleRegistrationTicket::query()->firstOrFail()->consumed_at);
    }

    public function test_registration_ticket_requires_at_least_a_32_byte_signing_key(): void
    {
        Config::set('modules.registration_ticket_key', str_repeat('k', 31));

        try {
            app(ModuleRegistrationTicketService::class)->issue(
                'qingyu_ip_agent',
                [],
                now()->addMinutes(5)
            );
            $this->fail('A short registration ticket key must be rejected.');
        } catch (UserApiException $exception) {
            $this->assertSame(503, $exception->httpStatus());
            $this->assertSame('registration_ticket_key_missing', $exception->errorCode());
        }

        $this->assertDatabaseCount('module_registration_ticket', 0);
    }

    private function createUser(string $email, string $sourceModule): UserAccount
    {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'testing-password',
            'nickname' => $email,
            'status' => 'active',
            'source_module' => $sourceModule,
            'register_ip' => '127.0.0.1',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
