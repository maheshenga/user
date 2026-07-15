<?php

namespace App\User;

use App\Models\ModuleRegistrationTicket;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class ModuleRegistrationTicketService
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function issue(string $module, array $claims, CarbonInterface $expiresAt): string
    {
        $module = $this->normalizeModule($module);
        $now = now();
        if (! $expiresAt->isAfter($now) || $expiresAt->greaterThan($now->copy()->addMinutes(30))) {
            throw new UserApiException('注册票据有效期必须在 30 分钟以内。', 422, 'registration_ticket_expiry_invalid');
        }

        foreach (['id', 'module', 'iat', 'exp'] as $reserved) {
            unset($claims[$reserved]);
        }
        $id = (string) Str::uuid();
        $payload = $claims + [
            'id' => $id,
            'module' => $module,
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ];
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UserApiException('注册票据声明无效。', 422, 'registration_ticket_claims_invalid');
        }
        if (strlen($json) > 4096) {
            throw new UserApiException('注册票据声明过大。', 422, 'registration_ticket_claims_invalid');
        }

        $encoded = $this->base64UrlEncode($json);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->key(), true));
        $ticket = 'mrt_'.$encoded.'.'.$signature;

        ModuleRegistrationTicket::query()->create([
            'id' => $id,
            'module' => $module,
            'token_hash' => hash('sha256', $ticket),
            'claims_json' => $payload,
            'expires_at' => $expiresAt,
        ]);

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function consume(string $ticket): array
    {
        $ticket = trim($ticket);
        if (! str_starts_with($ticket, 'mrt_') || strlen($ticket) > 8192) {
            throw $this->invalid();
        }
        $parts = explode('.', substr($ticket, 4));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $this->invalid();
        }

        [$encoded, $providedSignature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->key(), true));
        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw $this->invalid();
        }

        $json = $this->base64UrlDecode($encoded);
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid();
        }
        if (
            ! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || ! is_string($payload['module'] ?? null)
            || ! is_int($payload['iat'] ?? null)
            || ! is_int($payload['exp'] ?? null)
        ) {
            throw $this->invalid();
        }
        $module = $this->normalizeModule($payload['module']);
        if ($payload['exp'] <= now()->getTimestamp()) {
            throw new UserApiException('注册票据已过期。', 410, 'registration_ticket_expired');
        }

        return DB::transaction(function () use ($ticket, $payload, $module): array {
            $record = ModuleRegistrationTicket::query()
                ->where('token_hash', hash('sha256', $ticket))
                ->lockForUpdate()
                ->first();
            if (
                $record === null
                || $record->id !== $payload['id']
                || $record->module !== $module
            ) {
                throw $this->invalid();
            }
            if ($record->consumed_at !== null) {
                throw new UserApiException('注册票据已被使用。', 409, 'registration_ticket_replayed');
            }
            if ($record->expires_at === null || ! $record->expires_at->isFuture()) {
                throw new UserApiException('注册票据已过期。', 410, 'registration_ticket_expired');
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return $payload;
        });
    }

    private function normalizeModule(string $module): string
    {
        $module = strtolower(trim($module));
        if (strlen($module) > 80 || preg_match('/^[a-z][a-z0-9._-]*$/', $module) !== 1) {
            throw $this->invalid();
        }

        return $module;
    }

    private function key(): string
    {
        $key = (string) config('modules.registration_ticket_key', '');
        if (strlen($key) < 32) {
            throw new UserApiException('注册票据签名密钥未配置。', 503, 'registration_ticket_key_missing');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw $this->invalid();
        }

        return $decoded;
    }

    private function invalid(): UserApiException
    {
        return new UserApiException('注册票据无效。', 422, 'registration_ticket_invalid');
    }
}
