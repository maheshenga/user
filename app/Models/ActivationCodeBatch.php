<?php

namespace App\Models;

class ActivationCodeBatch extends BaseModel
{
    protected $table = 'activation_code_batch';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'vip_level' => 'integer',
        'duration_days' => 'integer',
        'is_commissionable' => 'boolean',
        'first_level_reward' => 'decimal:2',
        'second_level_reward' => 'decimal:2',
        'expires_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
