<?php

namespace App\Models;

class UserBalanceLedger extends BaseModel
{
    protected $table = 'user_balance_ledger';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'frozen_before' => 'decimal:2',
        'frozen_after' => 'decimal:2',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
