<?php

namespace App\Models;

class UserSecurityLog extends BaseModel
{
    protected $table = 'user_security_log';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'metadata_json' => 'array',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
