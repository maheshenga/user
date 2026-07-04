<?php

namespace App\Models;

class UserProfile extends BaseModel
{
    protected $table = 'user_profile';

    protected $casts = [
        'metadata_json' => 'array',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
