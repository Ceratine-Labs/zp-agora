<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class CountingAreaMenuSeeder extends Seeder
{
    public int $seedOrder = 72;

    public function run(): void
    {
        // Turn the existing Setup placeholder into a working link, retaining
        // its identity and any menu ticks customers have already assigned.
        MenuService::item('ho', 'setup', [
            'path' => 'business/counting-areas', 'parent' => 'business', 'label' => 'Counting areas',
            'route' => 'app.stockrecon.countingareas', 'permission' => 'stockrecon.countingareas.view',
            'sort' => 50,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'stock/counting-areas', 'parent' => 'stock', 'label' => 'Counting areas',
            'route' => 'app.stockrecon.countingareas', 'permission' => 'stockrecon.countingareas.view',
            'sort' => 30,
        ]);
    }
}
