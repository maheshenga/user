<?php

namespace App\Models;

class UserRiskEvent extends BaseModel
{
    protected $table = 'user_risk_event';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'detail_json' => 'array',
        'reviewed_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
