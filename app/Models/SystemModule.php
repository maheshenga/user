<?php

namespace App\Models;

class SystemModule extends BaseModel
{
    protected $table = 'system_module';

    protected $guarded = [];

    protected $casts = [
        'config_json' => 'array',
    ];
}
