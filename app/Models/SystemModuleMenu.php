<?php

namespace App\Models;

class SystemModuleMenu extends BaseModel
{
    public $timestamps = true;

    protected $dateFormat = 'Y-m-d H:i:s';

    public static function bootSoftDeletes() {}

    protected $table = 'system_module_menu';

    protected $guarded = [];
}
