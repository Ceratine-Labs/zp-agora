<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Http\Middleware\ResolveBranchContext;
use Modules\Core\Services\MenuService;
use Modules\Core\View\Composers\ShellComposer;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'core');

        $this->app->singleton(MenuService::class);
    }

    public function boot(): void
    {
        // Every request inside /app resolves the workspace, the branch and the
        // user's grants before a controller runs, so the global branch scope
        // is never consulted before it has been told anything.
        $this->app['router']->pushMiddlewareToGroup('web', ResolveBranchContext::class);

        // The shell needs the menu tree and the branch list on every page.
        // A composer keeps that out of 40 controllers.
        $this->app['view']->composer(
            ['components.app-shell'],
            ShellComposer::class,
        );
    }
}
