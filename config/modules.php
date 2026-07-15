<?php

return [
    'path' => base_path('modules'),
    'host_version' => env('EASYADMIN_CORE_VERSION', '8.0.0'),
    'asset_route_prefix' => 'module-assets',
    'allowed_types' => ['core', 'official', 'partner', 'community', 'private'],
    'local_unsigned_types' => ['core', 'official', 'private'],
    'production_requires_signature_for' => ['partner', 'community'],
    'production_in_process_trust_levels' => ['core', 'official', 'private'],
    'signing_key' => env('MODULE_SIGNING_KEY', ''),
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
