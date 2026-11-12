<?php

namespace Modules\Masters\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Masters module — the Setup screens over the business's reference data
 * (plan §3.2, slot 02). T021 brings the branch master's screens; T022 adds
 * profit centres, categories, areas and the rest here.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class MastersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'masters');
    }

    public function boot(): void
    {
        //
    }
}
