<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApiRefreshToken extends Model
{
    protected $table = 'user_api_refresh_tokens';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserApiSession::class, 'session_id');
    }
}
