<?php

namespace Modules\Debtors\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Debtors\Console\SendDebtorStatementsCommand;

/**
 * Debtors module.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and commands that are genuinely this module's own.
 */
class DebtorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'debtors');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SendDebtorStatementsCommand::class]);
        }
    }
}
