<?php

// Presentation and loader artwork share one definition. Destinations still
// come only from the person's active, accessible database navigation.
$landingModules = [
    'my-queue' => [
        'label' => 'My queue',
        'description' => 'Review outstanding work by site and queue.',
        'art' => 'my-queue',
        'route_prefixes' => [],
        'route_names' => ['app.reports.show'],
        'route_parameters' => ['report' => 'my-queue'],
        'preferred_routes' => ['app.reports.show'],
    ],
    'operations' => [
        'label' => 'Operations wall',
        'description' => 'Follow site activity and operational status.',
        'art' => 'operations',
        'route_prefixes' => ['app.operations.'],
        'preferred_routes' => ['app.operations.wall'],
    ],
    'utilities' => [
        'label' => 'Utilities',
        'description' => 'Meter readings, usage and prepaid utilities.',
        'art' => 'utilities',
        'route_prefixes' => ['app.utilities.'],
        'preferred_routes' => ['app.utilities.index'],
    ],
    'recon' => [
        'label' => 'Bank reconciliation',
        'description' => 'Match bank statements and resolve banking differences.',
        'art' => 'bank',
        'route_prefixes' => ['app.recon.'],
        'preferred_routes' => ['app.recon.index', 'app.recon.dashboard', 'app.recon.trace'],
    ],
    'stockrecon' => [
        'label' => 'Stock reconciliation',
        'description' => 'Balance stock counts and investigate exceptions.',
        'art' => 'cart',
        'route_prefixes' => ['app.stockrecon.'],
        'preferred_routes' => ['app.stockrecon.index', 'app.stockrecon.weekly', 'app.stockrecon.variance'],
    ],
    'cash' => [
        'label' => 'Cashups',
        'description' => 'Daily banking, tills, cash collections and staff shorts.',
        'art' => 'cashup',
        'route_prefixes' => ['app.cash.'],
        'preferred_routes' => ['app.cash.cashups', 'app.cash.banking', 'app.cash.zread'],
    ],
    'reports' => [
        'label' => 'Reports',
        'description' => 'Trading reports, performance and audit records.',
        'art' => 'reports',
        'route_prefixes' => ['app.reports.'],
        'preferred_routes' => ['app.reports.index'],
    ],
    'products' => [
        'label' => 'Products',
        'description' => 'Stock items, critical lines and product settings.',
        'art' => 'product',
        'route_prefixes' => ['app.master.'],
        'preferred_routes' => ['app.master.stock.index', 'app.master.critical.index'],
    ],
    'debtors' => [
        'label' => 'Debtors',
        'description' => 'Customer accounts, transactions and balances.',
        'art' => 'debtors',
        'route_prefixes' => ['app.debtors.'],
        'preferred_routes' => ['app.debtors.index'],
    ],
    'assets' => [
        'label' => 'Assets',
        'description' => 'The asset register, movements and repairs.',
        'art' => 'assets',
        'route_prefixes' => ['app.assets.'],
        'preferred_routes' => ['app.assets.register', 'app.assets.movement', 'app.assets.repairs', 'app.assets.masters'],
    ],
    'access' => [
        'label' => 'Users and access',
        'description' => 'Manage people, roles and module access.',
        'art' => 'settings',
        'route_prefixes' => ['app.setup.'],
        'preferred_routes' => ['app.setup.users.index', 'app.setup.roles.index'],
    ],
];

$moduleLoaders = [];
$routeLoaders = [];
foreach ($landingModules as $module) {
    foreach ($module['route_prefixes'] as $prefix) {
        $moduleLoaders[$prefix.'*'] = $module['art'];
    }
    foreach ($module['route_names'] ?? [] as $route) {
        $routeLoaders[] = ['route' => $route, 'parameters' => $module['route_parameters'] ?? [], 'art' => $module['art']];
    }
}

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
        'pos_reference' => env('MIST_REFERENCE_DB_DATABASE', 'MIST_Main'),
        'reporting' => env('ALTERYX_DB_DATABASE', 'Alteryx'),
    ],

    // The same motif identifies a module on the landing page and while loading.
    'landing_modules' => $landingModules,
    'loaders' => $moduleLoaders,
    'route_loaders' => $routeLoaders,
];
