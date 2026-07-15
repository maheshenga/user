<?php

namespace App\Modules;

use App\Models\SystemModuleRelease;
use RuntimeException;

final class ModuleReleaseSigner
{
    public function sign(SystemModuleRelease $release): ?string
    {
        $keyId = trim((string) config('modules.signing_active_key_id', ''));
        $keys = $this->keys();

        if ($keyId !== '') {
            $key = $keys[$keyId] ?? '';
            if (strlen($key) < 32) {
                throw new RuntimeException('模块活动签名密钥未配置或长度不足。');
            }

            $release->key_id = $keyId;

            return hash_hmac('sha256', $this->payload($release, $keyId), $key);
        }

        $release->key_id = null;
        if (! app()->environment('production')) {
            $legacyKey = trim((string) config('modules.signing_key', ''));
            if ($legacyKey !== '') {
                return hash_hmac('sha256', $this->legacyPayload($release), $legacyKey);
            }
        }

        if ($this->requiresSignature((string) $release->trust_level)) {
            throw new RuntimeException('模块签名密钥环未配置，不能审核第三方模块。');
        }

        return null;
    }

    public function verify(SystemModuleRelease $release): bool
    {
        $signature = trim((string) $release->signature_hash);
        $keyId = trim((string) $release->key_id);
        if ($signature === '') {
            return $keyId === '' && ! $this->requiresSignature((string) $release->trust_level);
        }

        if ($keyId !== '') {
            $key = $this->keys()[$keyId] ?? '';
            if (strlen($key) < 32) {
                return false;
            }

            $expected = hash_hmac('sha256', $this->payload($release, $keyId), $key);

            return hash_equals($expected, $signature);
        }

        if (app()->environment('production')) {
            return false;
        }

        $legacyKey = trim((string) config('modules.signing_key', ''));
        if ($legacyKey === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $this->legacyPayload($release), $legacyKey);

        return hash_equals($expected, $signature);
    }

    private function payload(SystemModuleRelease $release, string $keyId): string
    {
        return json_encode([
            $keyId,
            (string) $release->module,
            (string) $release->version,
            (string) $release->artifact_hash,
            (string) $release->trust_level,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function legacyPayload(SystemModuleRelease $release): string
    {
        return implode('|', [
            (string) $release->module,
            (string) $release->version,
            (string) $release->artifact_hash,
            (string) $release->trust_level,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function keys(): array
    {
        $keys = [];
        foreach ((array) config('modules.signing_keys', []) as $keyId => $key) {
            if (is_string($keyId) && is_string($key) && trim($keyId) !== '') {
                $keys[trim($keyId)] = trim($key);
            }
        }

        return $keys;
    }

    private function requiresSignature(string $trustLevel): bool
    {
        return app()->environment('production')
            && in_array($trustLevel, (array) config('modules.production_requires_signature_for', []), true);
    }
}
