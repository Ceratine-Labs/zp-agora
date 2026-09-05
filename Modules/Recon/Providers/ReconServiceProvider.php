<?php

namespace Modules\Recon\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Recon module.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class ReconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'recon');
    }

    public function boot(): void
    {
        //
    }
}
