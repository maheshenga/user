<?php

namespace App\Models;

class SystemModuleSource extends BaseModel
{
    public static function bootSoftDeletes(){}

    protected $table = 'system_module_source';

    protected $guarded = [];
}
