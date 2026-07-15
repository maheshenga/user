<?php

namespace App\Modules;

use InvalidArgumentException;

final class ModuleContractRegistry
{
    public function assertManifestSupported(ModuleManifest $manifest): void
    {
        $schemaVersion = $manifest->schemaVersion();
        if (! in_array($schemaVersion, (array) config('modules.supported_manifest_schema_versions', []), true)) {
            throw new InvalidArgumentException("不支持的 Manifest schema 版本：{$schemaVersion}");
        }

        $rawGatewayVersions = $manifest->toArray()['gateway_versions'] ?? [];
        if (! is_array($rawGatewayVersions)) {
            throw new InvalidArgumentException('Gateway 契约版本声明必须是对象。');
        }

        $permissionContracts = (array) config('modules.gateway_permission_contracts', []);
        foreach ($manifest->permissions() as $permission) {
            $gateway = $permissionContracts[$permission] ?? null;
            if (is_string($gateway) && ! array_key_exists($gateway, $rawGatewayVersions)) {
                throw new InvalidArgumentException("Gateway 契约版本未声明：{$gateway}");
            }
        }

        $supported = (array) config('modules.supported_gateway_versions', []);
        foreach ($rawGatewayVersions as $gateway => $version) {
            if (
                ! is_string($gateway)
                || preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $gateway) !== 1
                || ! is_string($version)
                || preg_match('/^\d+\.\d+$/', $version) !== 1
                || ! in_array($version, (array) ($supported[$gateway] ?? []), true)
            ) {
                $label = is_scalar($gateway) && is_scalar($version)
                    ? (string) $gateway.'@'.(string) $version
                    : '[invalid]';
                throw new InvalidArgumentException("不支持的 Gateway 契约版本：{$label}");
            }
        }
    }
}
