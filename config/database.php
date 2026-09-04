<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'agora'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        /*
        |----------------------------------------------------------------------
        | Agora's three customer connections
        |----------------------------------------------------------------------
        |
        | Four connections on one SQL Server instance, sharing one login.
        |
        | `agora` is the DEFAULT and the only one Agora writes to: its own
        | database, holding its own objects in the `agora` schema. The other
        | three are the customer's, and are READ-ONLY — `pumpit` (the ERP),
        | `mist_import` (the POS landing zone) and `alteryx` (reporting
        | extracts). Nothing in their `dbo` is ever altered; the legacy estate
        | is reached through `agora.vw_*` views, which name it across databases
        | (`PumpIT.dbo.SS_Branch`) because they now live somewhere else.
        |
        | The Agora database is created with `Latin1_General_CI_AS` to match
        | PumpIT. A different collation makes every cross-database string
        | comparison throw 'Cannot resolve collation conflict'.
        |
        | encrypt + trust_server_certificate are BOTH required. ODBC Driver 18
        | encrypts by default and validates the server certificate; this
        | instance's certificates are self-signed, so validation is declined
        | explicitly. Dropping either one fails the connection outright.
        |
        */

        'agora' => [
            'driver' => 'sqlsrv',
            'host' => env('AGORA_DB_HOST', '127.0.0.1'),
            'port' => env('AGORA_DB_PORT', '1433'),
            'database' => env('AGORA_DB_DATABASE', 'Agora'),
            'username' => env('AGORA_DB_USERNAME'),
            'password' => env('AGORA_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('AGORA_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('AGORA_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        'pumpit' => [
            'driver' => 'sqlsrv',
            'host' => env('PUMPIT_DB_HOST', '127.0.0.1'),
            'port' => env('PUMPIT_DB_PORT', '1433'),
            'database' => env('PUMPIT_DB_DATABASE', 'PumpIT'),
            'username' => env('PUMPIT_DB_USERNAME'),
            'password' => env('PUMPIT_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('PUMPIT_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('PUMPIT_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        'mist_import' => [
            'driver' => 'sqlsrv',
            'host' => env('MIST_DB_HOST', '127.0.0.1'),
            'port' => env('MIST_DB_PORT', '1433'),
            'database' => env('MIST_DB_DATABASE', 'MIST_Import'),
            'username' => env('MIST_DB_USERNAME'),
            'password' => env('MIST_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('MIST_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('MIST_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        'alteryx' => [
            'driver' => 'sqlsrv',
            'host' => env('ALTERYX_DB_HOST', '127.0.0.1'),
            'port' => env('ALTERYX_DB_PORT', '1433'),
            'database' => env('ALTERYX_DB_DATABASE', 'Alteryx'),
            'username' => env('ALTERYX_DB_USERNAME'),
            'password' => env('ALTERYX_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('ALTERYX_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('ALTERYX_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [

        // Schema-qualified so the ledger lands in `agora`, not `dbo`.
        'table' => 'agora.Migration',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
