<?php

namespace App\Models;

class VipPlan extends BaseModel
{
    protected $table = 'vip_plan';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'is_commissionable' => 'boolean',
        'price' => 'decimal:2',
        'first_level_rate' => 'decimal:4',
        'second_level_rate' => 'decimal:4',
        'benefits_json' => 'array',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
