<?php

return [
    'path' => base_path('modules'),
    'host_version' => env('EASYADMIN_CORE_VERSION', '8.0.0'),
    'supported_manifest_schema_versions' => ['1.0'],
    'supported_gateway_versions' => [
        'member' => ['1.0'],
        'invitation' => ['1.0'],
        'vip' => ['1.0'],
        'activation_code' => ['1.0'],
        'balance' => ['1.0'],
        'affiliate' => ['1.0'],
        'audit' => ['1.0'],
        'notification' => ['1.0'],
    ],
    'gateway_permission_contracts' => [
        'user:read' => 'member',
        'invite:read' => 'invitation',
        'vip:read' => 'vip',
        'vip:write' => 'vip',
        'activation-code:write' => 'activation_code',
        'balance:read' => 'balance',
        'balance:write' => 'balance',
        'affiliate:write' => 'affiliate',
        'audit:write' => 'audit',
        'notification:write' => 'notification',
    ],
    'asset_route_prefix' => 'module-assets',
    'allowed_types' => ['core', 'official', 'partner', 'community', 'private'],
    'local_unsigned_types' => ['core', 'official', 'private'],
    'production_requires_signature_for' => ['partner', 'community'],
    'production_in_process_trust_levels' => ['core', 'official', 'private'],
    'signing_active_key_id' => env('MODULE_SIGNING_ACTIVE_KEY_ID', ''),
    'signing_keys' => (static function (): array {
        $decoded = json_decode((string) env('MODULE_SIGNING_KEYS', '{}'), true);
        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach ($decoded as $keyId => $key) {
            if (is_string($keyId) && is_string($key) && trim($keyId) !== '') {
                $keys[trim($keyId)] = trim($key);
            }
        }

        return $keys;
    })(),
    'signing_key' => env('MODULE_SIGNING_KEY', ''),
    'worker' => [
        'url' => env('MODULE_WORKER_URL', ''),
        'protocol_version' => env('MODULE_WORKER_PROTOCOL_VERSION', '1.0'),
        'active_key_id' => env('MODULE_WORKER_ACTIVE_KEY_ID', ''),
        'keys' => (static function (): array {
            $decoded = json_decode((string) env('MODULE_WORKER_KEYS', '{}'), true);
            if (! is_array($decoded)) {
                return [];
            }

            return array_filter(
                $decoded,
                static fn (mixed $key, mixed $keyId): bool => is_string($keyId) && is_string($key) && trim($keyId) !== '',
                ARRAY_FILTER_USE_BOTH
            );
        })(),
        'timeout_seconds' => max(1, (int) env('MODULE_WORKER_TIMEOUT_SECONDS', 10)),
        'connect_timeout_seconds' => max(1, (int) env('MODULE_WORKER_CONNECT_TIMEOUT_SECONDS', 3)),
        'max_response_bytes' => max(1, (int) env('MODULE_WORKER_MAX_RESPONSE_BYTES', 1048576)),
        'clock_skew_seconds' => max(1, (int) env('MODULE_WORKER_CLOCK_SKEW_SECONDS', 300)),
        'health_cache_seconds' => max(0, (int) env('MODULE_WORKER_HEALTH_CACHE_SECONDS', 30)),
    ],
    'registration_ticket_key' => env('MODULE_REGISTRATION_TICKET_KEY', ''),
    'legacy_client_routes' => [
        'qingyu_ip_agent' => [
            'enabled' => env('MODULE_LEGACY_CLIENT_ROUTES_ENABLED', true),
            'sunset' => env('MODULE_LEGACY_CLIENT_SUNSET', 'Sun, 31 Jan 2027 00:00:00 GMT'),
            'successors' => [
                'bootstrap' => '/api/v1/modules/qingyu-ip-agent/bootstrap',
                'register' => '/api/v1/auth/modules/qingyu_ip_agent/register',
                'login' => '/api/v1/auth/modules/qingyu_ip_agent/login',
                'profile' => '/api/v1/auth/profile',
                'activate' => '/api/v1/modules/qingyu-ip-agent/activation-codes/redeem',
                'parseContent' => '/api/v1/modules/qingyu-ip-agent/content/parse',
                'rewrite' => '/api/v1/modules/qingyu-ip-agent/content/rewrite',
                'sampleAudio' => '/api/v1/modules/qingyu-ip-agent/sample-audio',
                'sendResetCode' => '/api/v1/auth/password/forgot',
                'resetPassword' => '/api/v1/auth/password/reset',
                'logout' => '/api/v1/auth/logout',
                'updateProfile' => '/api/v1/auth/profile',
                'updatePassword' => '/api/v1/auth/password/reset',
            ],
        ],
    ],
    'reserved_admin_prefixes' => [],
    'allowed_permissions' => [
        'menu:write',
        'node:write',
        'api:user',
        'user:read',
        'invite:read',
        'vip:read',
        'vip:write',
        'activation-code:write',
        'balance:read',
        'balance:write',
        'affiliate:write',
        'audit:write',
        'notification:write',
    ],
    'cache_key' => 'easyadmin8.modules.enabled',
    'integrity_cache_seconds' => env('MODULE_INTEGRITY_CACHE_SECONDS', 60),
    'retention_days' => max(1, (int) env('MODULE_RETENTION_DAYS', 90)),
    'retention_limit' => max(1, min(5000, (int) env('MODULE_RETENTION_LIMIT', 500))),
    'api_default_daily_quota' => 500,
    'api_request_lease_seconds' => max(30, (int) env('MODULE_API_REQUEST_LEASE_SECONDS', 180)),
    'api_daily_quotas' => [
        'qingyu_ip_agent' => [
            'activation.redeem' => 50,
            'content.parse' => 200,
            'content.rewrite' => 100,
        ],
    ],
];
