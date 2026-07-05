<?php

namespace App\Models;

class ActivationCodeRedemption extends BaseModel
{
    protected $table = 'activation_code_redemption';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
