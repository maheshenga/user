<?php

return [
    'max_attempts' => max(1, (int) env('USER_NOTIFICATION_MAX_ATTEMPTS', 5)),
    'lease_seconds' => max(30, (int) env('USER_NOTIFICATION_LEASE_SECONDS', 300)),
    'retry_minutes' => max(1, (int) env('USER_NOTIFICATION_RETRY_MINUTES', 5)),
];
