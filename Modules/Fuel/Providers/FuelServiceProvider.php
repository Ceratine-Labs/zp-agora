<?php

namespace Modules\Fuel\Providers;

use Illuminate\Support\ServiceProvider;

class FuelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'fuel');
    }
}
