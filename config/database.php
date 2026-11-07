<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Default database connection to use.
    |--------------------------------------------------------------------------
    */
    'default' => env(key: 'DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [

        /*
        |--------------------------------------------------------------------------
        | Application Base connection
        |--------------------------------------------------------------------------
        */
        'mysql' => [
            'driver' => 'mysql',
            'dsn' => env(key: 'DB_DSN'),
            'username' => env(key: 'DB_USER'),
            'password' => env(key: 'DB_PASSWORD'),
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => env(key: 'DB_PERSISTENT'),
            ] + (extension_loaded('pdo_mysql') ? [
                \Pdo\Mysql::ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ] : []),
        ],

        // These connection names match the framework's supported PDO drivers.
        'pgsql' => [
            'driver' => 'pgsql',
            'dsn' => env(key: 'DB_DSN'),
            'username' => env(key: 'DB_USER'),
            'password' => env(key: 'DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ],
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'dsn' => env(key: 'DB_DSN'),
            'username' => env(key: 'DB_USER'),
            'password' => env(key: 'DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ],
        'oci' => [
            'driver' => 'oci',
            'dsn' => env(key: 'DB_DSN'),
            'username' => env(key: 'DB_USER'),
            'password' => env(key: 'DB_PASSWORD'),
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'dsn' => env(key: 'DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/database/codefy.sqlite',
            'username' => env(key: 'DB_USER'),
            'password' => env(key: 'DB_PASSWORD'),
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => env(key: 'DB_PERSISTENT'),
            ],
        ]
    ],

    'redis' => [
        'options' => [
            'prefix' => env(key: 'REDIS_PREFIX'),
            'persistent' => env(key: 'REDIS_PERSISTENT'),
        ],

        'default' => [
            'scheme' => env(key: 'REDIS_SCHEME'),
            'host' => env(key: 'REDIS_HOST'),
            'username' => env(key: 'REDIS_USERNAME'),
            'password' => env(key: 'REDIS_PASSWORD'),
            'port' => env(key: 'REDIS_PORT'),
            'max_retries' => env(key: 'REDIS_MAX_RETRIES', default: 10),
            'database' => env(key: 'REDIS_DB'),
        ],

        'cache' => [
            'scheme' => env(key: 'REDIS_SCHEME'),
            'host' => env(key: 'REDIS_HOST'),
            'username' => env(key: 'REDIS_USERNAME'),
            'password' => env(key: 'REDIS_PASSWORD'),
            'port' => env(key: 'REDIS_PORT'),
            'max_retries' => env(key: 'REDIS_MAX_RETRIES', default: 10),
            'database' => env(key: 'REDIS_CACHE_DB'),
        ],
    ],
];
