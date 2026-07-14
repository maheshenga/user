<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ModuleApiRequest extends Model
{
    protected $table = 'module_api_request';

    protected $guarded = [];

    protected $casts = [
        'response_json' => 'array',
        'finished_at' => 'datetime',
    ];
}
