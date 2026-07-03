<?php

namespace App\Models;

class SystemModuleVersion extends BaseModel
{
    protected $table = 'system_module_version';

    protected $guarded = [];

    protected $casts = [
        'manifest_json' => 'array',
    ];
}
