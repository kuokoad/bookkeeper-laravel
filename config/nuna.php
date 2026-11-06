<?php

return [

    /*
    | The shop's own day. Trading dates are DATE columns decided in this zone;
    | timestamps are stored in UTC.
    */
    'business_timezone' => 'Africa/Accra',

    /*
    | Seed invented demo shop data. Refused in production.
    */
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),

    /*
    | Read the caller's address from X-Forwarded-For / X-Real-IP. Only for a
    | reverse proxy that OVERWRITES those headers; otherwise callers write them,
    | and the sign-in ceiling (50 per 10 minutes, beside 10 per username)
    | counts one shop-wide bucket instead of one per address.
    */
    'trust_proxy_headers' => filter_var(env('NUNA_TRUST_PROXY_HEADERS', false), FILTER_VALIDATE_BOOL),

    /*
    | The folder holding mysqldump.exe and mysql.exe. Unset: the newest
    | C:\laragon\bin\mysql\*\bin, else whatever is on the PATH.
    */
    'mysql_bin' => env('NUNA_MYSQL_BIN'),

    /*
    | nuna:backup writes here and keeps the newest `keep` verified copies.
    */
    'backup' => [
        'dir' => env('NUNA_BACKUP_DIR', storage_path('backups')),
        'keep' => (int) env('NUNA_BACKUP_KEEP', 14),
        // Optional dump-only MySQL user for mysqldump (the installer creates one).
        'db_username' => env('NUNA_BACKUP_DB_USERNAME'),
        'db_password' => env('NUNA_BACKUP_DB_PASSWORD', ''),
    ],

    /*
    | The Windows service running Apache (scripts/install.ps1). nuna:restore
    | refuses while it is running; the updater stops and starts it.
    */
    'apache_service' => env('NUNA_APACHE_SERVICE', 'NunaBooksApache'),

    /*
    | scripts/update.ps1 reports how the last update went here.
    */
    'update_status_path' => storage_path('app/update-status.json'),

];
