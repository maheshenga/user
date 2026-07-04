<?php

namespace App\Models;

class UserAccount extends BaseModel
{
    protected $table = 'user_account';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
        'mobile_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'vip_expires_at' => 'datetime',
        'available_balance' => 'decimal:2',
        'frozen_balance' => 'decimal:2',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];

    protected function runSoftDelete()
    {
        $column = $this->getDeletedAtColumn();
        $time = time();

        $this->setKeysForSaveQuery($this->newModelQuery())->update([
            $column => $time,
        ]);

        $this->{$column} = $time;
        $this->syncOriginalAttributes([$column]);
        $this->fireModelEvent('trashed', false);
    }
}
