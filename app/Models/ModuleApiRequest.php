<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ModuleApiRequest extends Model
{
    protected $table = 'module_api_request';

    protected $guarded = [];

    protected $casts = [
        'response_json' => 'array',
        'lease_expires_at' => 'datetime',
        'attempt_count' => 'integer',
        'finished_at' => 'datetime',
    ];
}
