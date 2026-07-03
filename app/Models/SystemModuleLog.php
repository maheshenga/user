<?php

namespace App\Models;

class SystemModuleLog extends BaseModel
{
    public static function bootSoftDeletes(){}

    protected $table = 'system_module_log';

    protected $guarded = [];
}
