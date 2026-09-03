<?php

namespace App\Providers;

use App\Support\Modules\Module;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the HMVC layer: discovery, then each module's own provider.
 *
 * A module is self-contained (plan §3.2) — its models, services, views,
 * routes, migrations, translations and menu seeder all live under
 * Modules/{Name}. This provider is the only thing that knows that, so a
 * module never has to register its own view namespace or route file by hand;
 * it gets them by existing in the right shape.
 *
 * Routes are registered here rather than in each module's provider so the
 * prefix and middleware stack are identical across every module — one place
 * to change, and no module can accidentally publish itself outside `/app`.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class, fn () => new ModuleManager(
            config('agora.modules_path'),
            config('agora.modules_cache'),
        ));

        foreach ($this->modules() as $module) {
            foreach ($module->providers as $provider) {
                if (class_exists($provider)) {
                    $this->app->register($provider);
                }
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->modules() as $module) {
            $this->registerViews($module);
            $this->registerTranslations($module);
            $this->registerMigrations($module);
            $this->registerRoutes($module);
        }
    }

    /** @return array<string, Module> */
    protected function modules(): array
    {
        return $this->app->make(ModuleManager::class)->enabled();
    }

    protected function registerViews(Module $module): void
    {
        if ($module->has('Resources/views')) {
            // Referenced as view('core::auth.login') — the alias, never the path.
            $this->loadViewsFrom($module->path('Resources/views'), $module->alias);
        }

        if ($module->has('Resources/views/components')) {
            // <x-core::mega-menu> alongside the shared <x-mega-menu>.
            $this->loadViewComponentsAs($module->alias, []);
            $this->app['blade.compiler']->anonymousComponentPath(
                $module->path('Resources/views/components'),
                $module->alias,
            );
        }
    }

    protected function registerTranslations(Module $module): void
    {
        if ($module->has('Resources/lang')) {
            $this->loadTranslationsFrom($module->path('Resources/lang'), $module->alias);
        }
    }

    protected function registerMigrations(Module $module): void
    {
        if ($module->has('Database/Migrations')) {
            $this->loadMigrationsFrom($module->path('Database/Migrations'));
        }
    }

    protected function registerRoutes(Module $module): void
    {
        if ($module->has('Routes/web.php')) {
            Route::middleware('web')
                ->prefix('app')
                ->name('app.')
                ->group($module->path('Routes/web.php'));
        }

        if ($module->has('Routes/api.php')) {
            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group($module->path('Routes/api.php'));
        }

        // Routes that must sit outside /app — sign-in, the health probe, a
        // public callback. Rare by design: a module declaring one is saying
        // this page is reachable without the app shell.
        if ($module->has('Routes/root.php')) {
            Route::middleware('web')->group($module->path('Routes/root.php'));
        }
    }
}
