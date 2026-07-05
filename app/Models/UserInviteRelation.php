<?php

namespace App\Models;

class UserInviteRelation extends BaseModel
{
    protected $table = 'user_invite_relation';

    protected $guarded = [];

    protected $casts = [
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
