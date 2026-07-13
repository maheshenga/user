<?php

namespace App\Models;

class UserPasswordReset extends BaseModel
{
    protected $table = 'user_password_reset';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
