<?php

declare(strict_types=1);

return [
    // Filesystem disk dead-drop:dump, dead-drop:dumps and dead-drop:pull read and write artifacts on. Defaults to
    // 'local'; set DEAD_DROP_DISK=s3 (or another configured disk) for a shared handoff.
    'disk' => env('DEAD_DROP_DISK', 'local'),
    'path' => env('DEAD_DROP_PATH', 'dead-drops'),

    // Directory (relative to config_path()) holding one <connection>.php per enrolled connection.
    'config_path' => 'dead-drop',

    // Directories (relative to base_path()) scanned for Eloquent models during inference.
    'model_paths' => ['app/Models'],

    // Which executor moves rows during dead-drop:dump ('php' ships with the package; register others with ExecutorManager::extend()).
    'executor' => env('DEAD_DROP_EXECUTOR', 'php'),

    'queue' => [
        'connection' => env('DEAD_DROP_QUEUE_CONNECTION'),
        'name' => env('DEAD_DROP_QUEUE', 'dead-drop'),
        'timeout' => (int) env('DEAD_DROP_QUEUE_TIMEOUT', 3600),
    ],

    'redaction' => [
        // When unset, derived from APP_KEY (DeadDrop\DeadDrop\Redaction\SaltResolver) so a dump works with no
        // redaction configuration at all. Set DEAD_DROP_REDACTION_SALT to pin the salt across apps or key rotations.
        'salt' => env('DEAD_DROP_REDACTION_SALT'),
        'email_domain' => env('DEAD_DROP_EMAIL_DOMAIN', 'example.test'),
    ],

    'binaries' => [
        'psql' => env('DEAD_DROP_PSQL'),
        'mysql' => env('DEAD_DROP_MYSQL'),
        'mysqlsh' => env('DEAD_DROP_MYSQLSH'),
    ],

    'pull' => [
        'allow_environments' => ['local', 'staging'],
        'after' => [],
    ],
];
