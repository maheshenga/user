<?php

namespace App\Models;

class UserInviteCode extends BaseModel
{
    protected $table = 'user_invite_code';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'metadata_json' => 'array',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
