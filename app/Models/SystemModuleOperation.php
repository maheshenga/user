<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemModuleOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'system_module_operation';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
