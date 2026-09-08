<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    |
    | Every object Agora creates lives in this schema, inside the PumpIT
    | database. `dbo` is the customer's legacy estate and is read through
    | `agora.vw_*` views only — Agora never alters it.
    |
    */

    'schema' => env('AGORA_SCHEMA', 'agora'),

    /*
    |--------------------------------------------------------------------------
    | Group branch
    |--------------------------------------------------------------------------
    |
    | Rows that are not specific to a trading site still carry a BranchId —
    | the group entity's, never NULL. A NULL branch is how legacy rows became
    | unreportable. Zululand Petroleum is dbo.SS_Branch.SSBranchId = 2.
    |
    */

    'group_branch_id' => (int) env('AGORA_GROUP_BRANCH_ID', 2),

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | HMVC modules are discovered by scanning this directory for module.json.
    | Discovery is cached in production (bootstrap/cache/agora-modules.php) and
    | re-scanned every request locally, so a new module appears without a
    | cache clear while you are building it.
    |
    */

    'modules_path' => base_path('Modules'),

    'modules_cache' => base_path('bootstrap/cache/agora-modules.php'),

    /*
    |--------------------------------------------------------------------------
    | The first administrator, and the browser-test account
    |--------------------------------------------------------------------------
    |
    | Read here rather than with env() at the call site: env() returns null once
    | the config is cached, so a seeder calling it directly works in development
    | and silently creates a user with no password in a cached environment.
    |
    */

    'admin' => [
        'email' => env('AGORA_ADMIN_EMAIL', 'ryan@revvtech.co.za'),
        'name' => env('AGORA_ADMIN_NAME', 'Ryan Cruickshank'),
        'password' => env('AGORA_ADMIN_PASSWORD'),
    ],

    'e2e' => [
        'email' => env('AGORA_E2E_EMAIL'),
        'password' => env('AGORA_E2E_PASSWORD'),
        'branch_id' => (int) env('AGORA_E2E_BRANCH_ID', 999),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Named so services do not hard-code connection strings. `pumpit` is also
    | the Laravel default, so models need no $connection unless they read a
    | secondary source.
    |
    */

    'connections' => [
        'app' => 'agora',
        'erp' => 'pumpit',
        'pos_landing' => 'mist_import',
        'reporting' => 'alteryx',
    ],

    /*
    |--------------------------------------------------------------------------
    | The legacy databases, by name
    |--------------------------------------------------------------------------
    |
    | Agora's objects live in their own database now, so a view over the legacy
    | estate has to name it across databases — `PumpIT.dbo.SS_Branch` rather
    | than `dbo.SS_Branch`. The name is config rather than literal because a
    | restore is commonly called something else (PumpIT_Staging), and a view
    | that hard-codes it silently reads the wrong estate.
    |
    | Same server is assumed, which is how ZP runs it. A linked server would
    | need four-part naming and is not supported here.
    |
    */

    'source_databases' => [
        'erp' => env('PUMPIT_DB_DATABASE', 'PumpIT'),
        'pos_landing' => env('MIST_DB_DATABASE', 'MIST_Import'),
        'reporting' => env('ALTERYX_DB_DATABASE', 'Alteryx'),
    ],
];
