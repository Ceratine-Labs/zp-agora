<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Deductions (T041) in head office: Control → Money, beside Staff shorts —
 * the mockup's "Cash shorts and deductions" (#/ho/reports, five tabs).
 */
class DeductionMenuSeeder extends Seeder
{
    /** After StaffShortMenuSeeder (52), whose Money section this sits in. */
    public int $seedOrder = 53;

    public function run(): void
    {
        MenuService::item('ho', 'control', [
            'path' => 'money/deductions',
            'parent' => 'money',
            'label' => 'Cash shorts and deductions',
            'route' => 'app.cash.deductions',
            'permission' => 'cash.deductions.view',
            'hint' => '5 tabs',
            'sort' => 37,
        ]);

        $this->command?->info('  Cash: Cash shorts and deductions on Control → Money (head office).');
    }
}
