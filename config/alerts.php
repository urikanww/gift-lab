<?php

declare(strict_types=1);

return [
    // Where unhandled-exception alerts are emailed. Null => fall back to the
    // staff-admin/superadmin list (see StaffNotifier::opsRecipients()).
    'ops_email' => env('OPS_ALERT_EMAIL'),

    // One alert per identical exception signature per this many minutes, so a
    // 500-storm can't flood the ops inbox.
    'exception_throttle_minutes' => (int) env('OPS_ALERT_THROTTLE', 15),
];
