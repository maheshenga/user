<?php

return [
    'path' => base_path('modules'),
    'asset_route_prefix' => 'module-assets',
    'allowed_types' => ['core', 'official', 'partner', 'community', 'private'],
    'local_unsigned_types' => ['core', 'official', 'private'],
    'production_requires_signature_for' => ['partner', 'community'],
    'cache_key' => 'easyadmin8.modules.enabled',
];
