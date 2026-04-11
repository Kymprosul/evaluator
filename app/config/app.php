<?php

declare(strict_types=1);

$defaultDatabasePath = storage_path('data/evaluator.sqlite');
$driver = (string) env_value('DB_DRIVER', 'sqlite');
$configuredDatabase = (string) env_value('DB_DATABASE', 'storage/data/evaluator.sqlite');
$databasePath = $driver === 'sqlite'
    ? (preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredDatabase) === 1
        ? $configuredDatabase
        : project_path($configuredDatabase))
    : $configuredDatabase;

return [
    'app' => [
        'name' => (string) env_value('APP_NAME', 'Evaluator'),
        'url' => (string) env_value('APP_URL', ''),
        'timezone' => (string) env_value('APP_TIMEZONE', 'Europe/Madrid'),
        'session_name' => (string) env_value('SESSION_NAME', 'evaluator_session'),
    ],
    'db' => [
        'driver' => $driver,
        'host' => (string) env_value('DB_HOST', '127.0.0.1'),
        'port' => (string) env_value('DB_PORT', '3306'),
        'database' => $configuredDatabase === '' ? $defaultDatabasePath : $databasePath,
        'username' => (string) env_value('DB_USERNAME', ''),
        'password' => (string) env_value('DB_PASSWORD', ''),
    ],
];
