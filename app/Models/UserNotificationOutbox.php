<?php

namespace App\Models;

final class UserNotificationOutbox extends BaseModel
{
    protected $table = 'user_notification_outbox';
    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'payload_json' => 'encrypted:array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
