<?php

namespace App\Models;

class UserLoginLog extends BaseModel
{
    public static function bootSoftDeletes() {}

    protected $table = 'user_login_log';

    protected $guarded = [];

    protected $casts = [
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
