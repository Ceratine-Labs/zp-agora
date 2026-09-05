<?php

namespace Modules\Core\Providers;

use App\Support\Badges\BadgeRegistry;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Badges\ExceptionsOpenBadge;
use Modules\Core\Badges\LoadsFailedBadge;
use Modules\Core\Badges\PurchaseRequestsAwaitingBadge;
use Modules\Core\Badges\ZReadsUnallocatedBadge;
use Modules\Core\Console\MigrateUsersCommand;
use Modules\Core\Http\Middleware\RequirePasswordChange;
use Modules\Core\Http\Middleware\ResolveBranchContext;
use Modules\Core\Services\MenuService;
use Modules\Core\View\Composers\ShellComposer;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'core');

        $this->app->singleton(MenuService::class);

        /*
         | The badge registry is a singleton because it is a REGISTRY: a module
         | booting later registers into the same instance the sign-in page, the
         | bell (T011) and the menu items read out of. A fresh instance per
         | resolve would mean each surface saw only whatever it happened to
         | register itself.
         */
        $this->app->singleton(BadgeRegistry::class);
    }

    public function boot(): void
    {
        /*
         | Core's two web middleware.
         |
         | ResolveBranchContext: every request inside /app resolves the
         | workspace, the branch and the user's grants before a controller
         | runs, so the branch global scope is never consulted before it has
         | been told anything.
         |
         | RequirePasswordChange: nobody gets past the change-password screen
         | while their password is one somebody else chose. One boolean read
         | for everybody who has set one.
         */
        $this->pushWebMiddleware([
            ResolveBranchContext::class,
            RequirePasswordChange::class,
        ]);

        // The shell needs the menu tree and the branch list on every page.
        // A composer keeps that out of 40 controllers.
        $this->app['view']->composer(
            ['components.app-shell'],
            ShellComposer::class,
        );

        /*
         | Placeholders for the four figures on the sign-in page. Each is
         | registered under the key its owning module will take over — see
         | Modules\Core\Badges\PendingBadge. Registering here rather than in a
         | config array means the classes are type-checked and the reason each
         | one exists is written on the class.
         */
        $badges = $this->app->make(BadgeRegistry::class);
        $badges->register(LoadsFailedBadge::class);
        $badges->register(ExceptionsOpenBadge::class);
        $badges->register(ZReadsUnallocatedBadge::class);
        $badges->register(PurchaseRequestsAwaitingBadge::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateUsersCommand::class]);
        }
    }

    /**
     * Add middleware to the `web` group so it actually runs.
     *
     * `$router->pushMiddlewareToGroup()` alone is NOT enough, and the way it
     * fails is silent. The HTTP kernel keeps its own copy of the groups and
     * re-syncs the router from it — `Kernel::setMiddlewareGroups()`, which
     * ApplicationBuilder calls from an `afterResolving` hook. Whether that
     * hook fires before or after providers boot depends on when the kernel is
     * first resolved, and it differs between the two environments that matter:
     *
     *   - a real request resolves the kernel, THEN bootstraps and boots, so a
     *     router push survives;
     *   - the test harness resolves the kernel inside `createApplication()`
     *     and the hook fires there, so a router push made in boot() is
     *     discarded and the middleware never runs.
     *
     * The consequence is worse than a failing test: a guard the suite cannot
     * see is a guard nobody knows is broken. So this appends to the KERNEL's
     * own list, which the kernel then syncs to the router itself, and does it
     * whichever side of boot() the kernel happens to appear on:
     *
     *   - already resolved (a real request, which makes the kernel before it
     *     bootstraps) — append now;
     *   - not yet resolved (the test harness bootstraps through the CONSOLE
     *     kernel, so the HTTP one is first built by the first `$this->get()`)
     *     — append the moment it is, after ApplicationBuilder's own hook has
     *     laid down the defaults.
     *
     * `appendMiddlewareToGroup` is idempotent, so a second registration in a
     * long-lived worker adds nothing twice.
     *
     * @param  array<int, class-string>  $middleware
     */
    protected function pushWebMiddleware(array $middleware): void
    {
        // The instanceof is not defensive padding: appendMiddlewareToGroup is
        // on the concrete Foundation kernel, not on the contract the container
        // is keyed by, and narrowing to it is what makes that call honest
        // rather than a suppressed warning.
        $append = function (HttpKernel $kernel) use ($middleware): void {
            if (! $kernel instanceof FoundationKernel) {
                return;
            }

            foreach ($middleware as $class) {
                $kernel->appendMiddlewareToGroup('web', $class);
            }
        };

        if ($this->app->resolved(HttpKernel::class)) {
            $append($this->app->make(HttpKernel::class));

            return;
        }

        $this->app->afterResolving(HttpKernel::class, $append);
    }
}
