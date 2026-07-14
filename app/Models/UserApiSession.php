<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserApiSession extends Model
{
    protected $table = 'user_api_sessions';

    protected $guarded = [];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(UserApiRefreshToken::class, 'session_id');
    }
}
