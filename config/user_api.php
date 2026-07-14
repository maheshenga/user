<?php

return [
    'access_token_minutes' => 15,
    'refresh_token_days' => 30,

    'modules' => [
        'qingyu_ip_agent' => [
            'abilities' => [
                'profile:read',
                'vip:read',
                'activation:redeem',
                'content:parse',
                'content:rewrite',
                'module:qingyu_ip_agent',
            ],
        ],
    ],
];
