<?php

namespace App\Models;

class SystemModuleRelease extends BaseModel
{
    public $timestamps = true;

    protected $dateFormat = 'Y-m-d H:i:s';

    public static function bootSoftDeletes() {}

    protected $table = 'system_module_release';

    protected $guarded = [];

    protected $casts = [
        'manifest_json' => 'array',
        'reviewed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];
}
