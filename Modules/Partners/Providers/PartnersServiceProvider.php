<?php

namespace Modules\Partners\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Partners module — suppliers (creditors) and account customers (debtors),
 * plan §3.2 slot 04, T024.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class PartnersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'partners');
    }

    public function boot(): void
    {
        //
    }
}
