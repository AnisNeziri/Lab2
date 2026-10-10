<?php

return [
    'mail_enabled' => (bool) env('AIMS_MAIL_ENABLED', false),
    'timezone' => env('AIMS_COMPANY_TIMEZONE'),
    'min_free_bytes' => (int) env('AIMS_MIN_FREE_BYTES', 536870912),
    'worker_max_age' => 300,
    'backup_root' => env('AIMS_BACKUP_ROOT', storage_path('app/installation-backups')),
    'backup_passphrase_file' => env('AIMS_BACKUP_PASSPHRASE_FILE'),
    'backup_schedule' => env('AIMS_BACKUP_SCHEDULE', 'manual'),
    'backup_keep' => max(2, (int) env('AIMS_BACKUP_KEEP', 14)),
    'mysqldump' => env('AIMS_MYSQLDUMP', 'mariadb-dump'),
    'mysql' => env('AIMS_MYSQL', 'mariadb'),
];
