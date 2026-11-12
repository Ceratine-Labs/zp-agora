<?php

namespace Modules\People\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * People module.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'people');
    }

    public function boot(): void
    {
        //
    }
}
