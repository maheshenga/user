<?php

namespace Tests\Feature\Modules;

use App\Models\ModuleApiRequest;
use App\Models\UserAccount;
use App\Modules\ModuleApiException;
use App\Modules\ModuleApiRequestService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ModuleApiRequestLeaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_module_api_request_lease_fields_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('module_api_request', [
            'lease_token',
            'lease_expires_at',
            'attempt_count',
        ]));
    }

    public function test_active_processing_lease_remains_blocked(): void
    {
        $user = $this->createAccount('module-lease-active@example.com');
        $payload = ['text' => 'same payload'];
        $this->createProcessingRequest($user, $payload, now()->addMinutes(5));
        $callbackRan = false;

        try {
            app(ModuleApiRequestService::class)->execute(
                'qingyu_ip_agent',
                $user->id,
                'content.rewrite',
                'lease-request-active',
                $payload,
                function () use (&$callbackRan): array {
                    $callbackRan = true;

                    return ['content' => 'unexpected'];
                }
            );
            $this->fail('Expected active processing request to remain blocked.');
        } catch (ModuleApiException $exception) {
            $this->assertSame('request_in_progress', $exception->errorCode());
        }

        $this->assertFalse($callbackRan);
    }

    public function test_expired_processing_lease_is_reclaimed_and_completed(): void
    {
        $user = $this->createAccount('module-lease-stale@example.com');
        $payload = ['text' => 'same payload'];
        $record = $this->createProcessingRequest($user, $payload, now()->subMinute());

        $result = app(ModuleApiRequestService::class)->execute(
            'qingyu_ip_agent',
            $user->id,
            'content.rewrite',
            'lease-request-active',
            $payload,
            static fn (): array => ['content' => 'recovered']
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame('recovered', $result['data']['content']);
        $record->refresh();
        $this->assertSame('completed', $record->status);
        $this->assertSame(2, (int) $record->attempt_count);
        $this->assertNull($record->lease_token);
        $this->assertNull($record->lease_expires_at);
    }

    public function test_expired_worker_cannot_finalize_after_lease_ownership_changes(): void
    {
        $user = $this->createAccount('module-lease-owner@example.com');
        $payload = ['text' => 'same payload'];
        $record = $this->createProcessingRequest($user, $payload, now()->subMinute());

        try {
            app(ModuleApiRequestService::class)->execute(
                'qingyu_ip_agent',
                $user->id,
                'content.rewrite',
                'lease-request-active',
                $payload,
                function () use ($record): array {
                    ModuleApiRequest::query()->whereKey($record->id)->update([
                        'lease_token' => 'new-owner',
                        'lease_expires_at' => now()->addMinutes(5),
                    ]);

                    return ['content' => 'stale result'];
                }
            );
            $this->fail('Expected stale worker finalization to fail.');
        } catch (ModuleApiException $exception) {
            $this->assertSame('request_lease_lost', $exception->errorCode());
        }

        $record->refresh();
        $this->assertSame('processing', $record->status);
        $this->assertSame('new-owner', $record->lease_token);
        $this->assertNull($record->response_json);
    }

    private function createProcessingRequest(UserAccount $user, array $payload, mixed $expiresAt): ModuleApiRequest
    {
        ksort($payload, SORT_STRING);

        return ModuleApiRequest::query()->create([
            'module' => 'qingyu_ip_agent',
            'user_id' => $user->id,
            'operation' => 'content.rewrite',
            'request_id' => 'lease-request-active',
            'request_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'processing',
            'lease_token' => 'old-owner',
            'lease_expires_at' => $expiresAt,
            'attempt_count' => 1,
        ]);
    }

    private function createAccount(string $email): UserAccount
    {
        return UserAccount::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'nickname' => $email,
            'status' => 'active',
            'source_module' => 'qingyu_ip_agent',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
