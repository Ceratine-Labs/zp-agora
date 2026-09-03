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
    | Connections
    |--------------------------------------------------------------------------
    |
    | Named so services do not hard-code connection strings. `pumpit` is also
    | the Laravel default, so models need no $connection unless they read a
    | secondary source.
    |
    */

    'connections' => [
        'primary' => 'pumpit',
        'pos_landing' => 'mist_import',
        'reporting' => 'alteryx',
    ],
];
