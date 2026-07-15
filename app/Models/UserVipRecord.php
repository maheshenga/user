<?php

namespace App\Models;

class UserVipRecord extends BaseModel
{
    protected $table = 'user_vip_record';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s';

    public static function bootSoftDeletes() {}

    protected $casts = [
        'vip_level' => 'integer',
        'duration_days' => 'integer',
        'before_expires_at' => 'datetime',
        'after_expires_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
