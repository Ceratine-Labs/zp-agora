<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

class WeeklyStockMenuSeeder extends Seeder
{
    public int $seedOrder = 74;

    public function run(): void
    {
        foreach ([['ho', 'control', 'stock'], ['branch', 'stock', 'counting']] as [$workspace, $section, $parent]) {
            MenuService::item($workspace, $section, ['path' => $parent.'/weekly', 'parent' => $parent, 'label' => 'Weekly investigation',
                'route' => 'app.stockrecon.weekly', 'permission' => 'stockrecon.weekly.view', 'sort' => 40]);
        }
    }
}
