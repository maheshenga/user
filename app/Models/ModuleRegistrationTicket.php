<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ModuleRegistrationTicket extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'module_registration_ticket';

    protected $guarded = [];

    protected $casts = [
        'claims_json' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
