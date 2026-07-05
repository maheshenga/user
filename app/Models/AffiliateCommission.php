<?php

namespace App\Models;

class AffiliateCommission extends BaseModel
{
    protected $table = 'affiliate_commission';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'amount' => 'decimal:2',
        'audited_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
