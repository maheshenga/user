<?php

return [
    'access_token_minutes' => 15,
    'refresh_token_days' => 30,

    'allowed_abilities' => [
        'profile:read',
        'vip:read',
        'invite:read',
        'balance:read',
        'activation:redeem',
        'content:parse',
        'content:rewrite',
        'module:qingyu_ip_agent',
    ],

    'modules' => [
        'qingyu_ip_agent' => [
            'abilities' => [
                'profile:read',
                'vip:read',
                'invite:read',
                'balance:read',
                'activation:redeem',
                'content:parse',
                'content:rewrite',
                'module:qingyu_ip_agent',
            ],
        ],
    ],
];
