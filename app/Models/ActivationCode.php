<?php

namespace App\Models;

class ActivationCode extends BaseModel
{
    protected $table = 'activation_code';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'expires_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
