<?php

namespace App\Models;

class SystemModuleMigration extends BaseModel
{
    public static function bootSoftDeletes(){}

    protected $table = 'system_module_migration';

    protected $guarded = [];
}
