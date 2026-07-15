<?php

namespace App\Models;

final class UserNotificationOutbox extends BaseModel
{
    protected $table = 'user_notification_outbox';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    public static function bootSoftDeletes() {}

    protected $casts = [
        'payload_json' => 'encrypted:array',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
