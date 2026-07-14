<?php

namespace App\Modules;

use App\Models\SystemModuleRelease;
use RuntimeException;

final class ModuleReleaseSigner
{
    public function sign(SystemModuleRelease $release): ?string
    {
        $key = trim((string) config('modules.signing_key', ''));
        if ($key === '') {
            if ($this->requiresSignature((string) $release->trust_level)) {
                throw new RuntimeException('模块签名密钥未配置，不能审核第三方模块。');
            }

            return null;
        }

        return hash_hmac('sha256', $this->payload($release), $key);
    }

    public function verify(SystemModuleRelease $release): bool
    {
        $signature = trim((string) $release->signature_hash);
        if ($signature === '') {
            return ! $this->requiresSignature((string) $release->trust_level);
        }

        $expected = $this->sign($release);

        return $expected !== null && hash_equals($expected, $signature);
    }

    private function payload(SystemModuleRelease $release): string
    {
        return implode('|', [
            (string) $release->module,
            (string) $release->version,
            (string) $release->artifact_hash,
            (string) $release->trust_level,
        ]);
    }

    private function requiresSignature(string $trustLevel): bool
    {
        return app()->environment('production')
            && in_array($trustLevel, (array) config('modules.production_requires_signature_for', []), true);
    }
}
