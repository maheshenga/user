<?php

namespace App\Models;

class SystemModuleVersion extends BaseModel
{
    public static function bootSoftDeletes(){}

    protected $table = 'system_module_version';

    protected $guarded = [];

    protected $casts = [
        'manifest_json' => 'array',
    ];
}
