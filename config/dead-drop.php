<?php

declare(strict_types=1);

return [
    'disk' => env('DEAD_DROP_DISK', 's3'),
    'path' => env('DEAD_DROP_PATH', 'dead-drops'),

    // Directory (relative to config_path()) holding one <connection>.php per enrolled connection.
    'config_path' => 'dead-drop',

    // Directories (relative to base_path()) scanned for Eloquent models during inference.
    'model_paths' => ['app/Models'],

    'redaction' => [
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
