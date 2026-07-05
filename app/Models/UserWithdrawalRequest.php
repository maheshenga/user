<?php

namespace App\Models;

class UserWithdrawalRequest extends BaseModel
{
    protected $table = 'user_withdrawal_request';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'amount' => 'decimal:2',
        'account_snapshot_json' => 'array',
        'audited_at' => 'datetime',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
