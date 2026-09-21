<?php

namespace Modules\Product\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Product\Console\RefreshCountStatsCommand;

/**
 * Product module.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'product');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RefreshCountStatsCommand::class]);
        }
    }
}
