<?php

namespace Modules\Cash\Providers;

use App\Support\Badges\BadgeRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Cash\Badges\DropSafeInSafeBadge;
use Modules\Cash\Console\SendStaffShortMailCommand;

/**
 * Cash module.
 *
 * Views, routes, migrations and translations are wired up by
 * App\Providers\ModuleServiceProvider from this module's shape — this
 * provider is for bindings and event listeners that are genuinely this
 * module's own.
 */
class CashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'cash');
    }

    public function boot(): void
    {
        /*
         | "In the safe" (T039): the drop safe step of the branch console. The
         | console is T029 and not built; it will ask the registry for
         | cash.dropsafe.insafe and get the count, the wording and the link.
         */
        $this->app->make(BadgeRegistry::class)->register(DropSafeInSafeBadge::class);

        /*
         | Staff shorts (T040): the mails a press could not send — a failed send,
         | or a short a cash-bag match raised inside its procedure.
         */
        if ($this->app->runningInConsole()) {
            $this->commands([SendStaffShortMailCommand::class]);
        }
    }
}
