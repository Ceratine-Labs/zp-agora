<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The navigation, from the mockup's NAV_HO and NAV_BRANCH.
 *
 * Structure, not decoration: depth 1 is a column heading in the mega panel,
 * depth 2 a link, and depth 3+ a group that expands in place. The Reports
 * column carries a real third level — the report library by category, and the
 * legacy catalogue underneath it — because that is where the customer's own
 * hierarchy actually goes three deep, not because the renderer needed
 * exercising.
 *
 * Items whose screens do not exist yet are seeded with no route. They render
 * as plain text marked "not built yet" rather than as links that 404 — the
 * menu is the plan made visible, and hiding the unbuilt half would make it a
 * worse plan.
 *
 * Everything here upserts on (workspace, section, path), so re-running is safe
 * and a relabel is an update.
 */
class MenuSeeder extends Seeder
{
    /** Sections and items are one seeder because an item addresses its section. */
    public int $seedOrder = 30;

    public function run(): void
    {
        $this->headOffice();
        $this->branch();

        $this->command?->info('  Menu: 4 head-office sections, 4 branch sections.');
    }

    private function headOffice(): void
    {
        MenuService::section('ho', 'today', 'Today', 10);
        MenuService::section('ho', 'trade', 'Trade', 20);
        MenuService::section('ho', 'control', 'Control', 30);
        MenuService::section('ho', 'setup', 'Setup', 40);

        $this->column('ho', 'today', 'the-day', 'The day', 10, [
            ['my-queue', 'My queue', 'live'],
            ['day-close', 'Day close status', null, 'app.dashboard'],
            ['overnight-loads', 'Overnight loads', 'Live'],
            ['unallocated-zreads', 'Unallocated Z-reads', 'Live'],
            ['open-cashups', 'Open cashups', 'Live'],
        ]);

        $this->column('ho', 'today', 'decisions', 'Waiting on a decision', 20, [
            ['purchase-approvals', 'Purchase approvals', '607'],
            ['staff-shorts', 'Staff shorts', 'Live'],
            ['drop-safe', 'Drop safe collections', 'Live'],
            ['waste', 'Waste to approve', 'Live'],
        ]);

        $this->column('ho', 'today', 'capture', 'Capture', 30, [
            ['pump-readings', 'Pump readings', 'Live'],
            ['fuel-input', 'Fuel input', 'Live'],
            ['daily-banking', 'Daily banking', 'Live'],
            ['stock-count', 'Stock count', 'Live'],
            ['utility-meters', 'Utility meters', 'Live'],
            ['import-pos', 'Import POS files', null],
        ]);

        $this->column('ho', 'trade', 'the-group', 'The group', 10, [
            ['exco-pack', 'Weekly Exco pack', 'Live'],
            ['trading-position', 'Group trading position', 'Live'],
            ['league-table', 'Site league table', null],
            ['budget-actual', 'Budget against actual', 'Live'],
        ]);

        $this->column('ho', 'trade', 'profit-centre', 'By profit centre', 20, [
            ['fuel', 'Fuel and forecourt', null],
            ['shop', 'Convenience shop', null],
            ['ok-stores', 'OK stores', 'Live'],
            ['qsr', 'Franchise brands', null],
            ['lubes', 'Lubricants', null],
            ['virtual', 'Airtime, lotto and vouchers', 'Live'],
        ]);

        // Three levels deep, and the depth is the customer's own: the library
        // groups 97 report definitions by category, and each category still
        // has to reach the legacy catalogue it replaced.
        MenuService::item('ho', 'trade', ['path' => 'reports', 'label' => 'Reports', 'sort' => 30]);
        MenuService::item('ho', 'trade', ['path' => 'reports/all', 'parent' => 'reports', 'label' => 'All reports', 'hint' => '97', 'sort' => 10]);

        foreach ([
            'exco' => ['Exco', 8],
            'sales' => ['Sales', 6],
            'margin' => ['Margin', 6],
            'products-stock' => ['Products and stock', 8],
            'stock-counts' => ['Stock counts', 7],
            'cash-banking' => ['Cash and banking', 10],
        ] as $slug => [$label, $count]) {
            MenuService::item('ho', 'trade', [
                'path' => "reports/{$slug}", 'parent' => 'reports',
                'label' => $label, 'hint' => (string) $count, 'sort' => 20,
            ]);
            MenuService::item('ho', 'trade', [
                'path' => "reports/{$slug}/library", 'parent' => "reports/{$slug}",
                'label' => 'Report library', 'sort' => 10,
            ]);
            MenuService::item('ho', 'trade', [
                'path' => "reports/{$slug}/legacy", 'parent' => "reports/{$slug}",
                'label' => 'Legacy catalogue', 'hint' => 'was', 'sort' => 20,
            ]);
        }

        $this->column('ho', 'control', 'exceptions', 'Exceptions', 10, [
            ['register', 'Exception register', '12'],
            ['cashback', 'Diesel cashback forensic', 'Live'],
            ['stock-variance', 'Stock counts and variance', 'Live'],
            ['margin-breaks', 'Margin breaks', 'Live'],
            ['pump-checks', 'Pump and meter checks', 'Live'],
        ]);

        $this->column('ho', 'control', 'money', 'Money', 20, [
            ['bank-recon', 'Bank reconciliation', 'Live'],
            ['cash-bags', 'Cash bags and devices', 'Live'],
            ['banking-exceptions', 'Daily banking exceptions', 'Live'],
            ['deductions', 'Cash shorts and deductions', '5 tabs'],
            ['settlement', 'Imports and settlement files', '23'],
        ]);

        $this->column('ho', 'control', 'governance', 'Governance', 30, [
            ['audit-trail', 'Audit trail', null],
            ['load-errors', 'Load errors', 'Live'],
            ['area-locks', 'Stock recon area locks', 'Live'],
            ['site-rating', 'Site rating against incentive', null],
        ]);

        $this->column('ho', 'setup', 'business', 'The business', 10, [
            ['branches', 'Branches', '31'],
            ['profit-centres', 'Profit centres', null],
            ['categories', 'Categories and GP bands', 'Live'],
            ['standard-categories', 'Standard categories', null],
            ['counting-areas', 'Counting areas', null],
        ]);

        $this->column('ho', 'setup', 'trading-rules', 'Trading rules', 20, [
            ['fuel-matrix', 'Fuel matrix', 'Live'],
            ['products', 'Products', null],
            ['critical-lines', 'Critical lines', null],
            ['suppliers', 'Suppliers', null],
            ['account-customers', 'Account customers', null],
            ['expense-codes', 'Expense codes', null],
        ]);

        $this->column('ho', 'setup', 'people-assets', 'People and assets', 30, [
            ['employees', 'Employees', null],
            ['users', 'Users and access', 'Live'],
            ['meters', 'Utility meters', null],
            ['assets', 'Asset register', 'Live'],
        ]);
    }

    private function branch(): void
    {
        MenuService::section('branch', 'today', 'Today', 10);
        MenuService::section('branch', 'stock', 'Stock', 20);
        MenuService::section('branch', 'trade', 'My site', 30);
        MenuService::section('branch', 'assets', 'Assets', 40);

        $this->column('branch', 'today', 'close-the-day', 'Close the day', 10, [
            ['my-day', 'My day', 'Live', 'app.dashboard'],
            ['pump-readings', 'Pump readings', 'Live'],
            ['fuel-input', 'Fuel input', 'Live'],
            ['zread', 'Z-read allocation', 'Live'],
            ['daily-banking', 'Daily banking', 'Live'],
            ['drop-safe', 'Drop safe', 'Live'],
        ]);

        $this->column('branch', 'today', 'raise', 'Raise', 20, [
            ['purchase-request', 'New purchase request', 'Live'],
            ['staff-short', 'Staff short', 'Live'],
            ['waste', 'Waste', 'Live'],
            ['utility-reading', 'Utility reading', 'Live'],
        ]);

        $this->column('branch', 'stock', 'counting', 'Counting', 10, [
            ['stock-count', 'Stock count', 'Live'],
            ['count-exceptions', 'Count exceptions', 'Live'],
            ['balancing', 'Balancing', 'Live'],
            ['area-locks', 'Area locks', 'Live'],
            ['month-end', 'Month end', null],
        ]);

        $this->column('branch', 'stock', 'production', 'Production', 20, [
            ['pre-production', 'Bulk pre-production', 'Live'],
            ['butchery', 'Butchery pre-production', 'Live'],
            ['critical-lines', 'Critical lines', 'Live'],
            ['products', 'Products', null],
        ]);

        $this->column('branch', 'trade', 'how-we-are-doing', 'How we are doing', 10, [
            ['scorecard', 'Site scorecard', 'Live'],
            ['reports', 'Reports', null],
            ['margin-breaks', 'Margin breaks', 'Live'],
            ['sales-by-product', 'Sales by product', null],
        ]);

        $this->column('branch', 'assets', 'equipment', 'Equipment', 10, [
            ['register', 'Asset register', 'Live'],
            ['movement', 'Movement', 'Live'],
            ['repairs', 'Repairs', 'Live'],
        ]);
    }

    /**
     * One column: a depth-1 heading and its links.
     *
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3?: string}>  $items
     */
    private function column(string $workspace, string $section, string $path, string $label, int $sort, array $items): void
    {
        MenuService::item($workspace, $section, ['path' => $path, 'label' => $label, 'sort' => $sort]);

        foreach ($items as $index => $item) {
            MenuService::item($workspace, $section, [
                'path' => $path.'/'.$item[0],
                'parent' => $path,
                'label' => $item[1],
                'hint' => $item[2],
                'route' => $item[3] ?? null,
                'sort' => ($index + 1) * 10,
            ]);
        }
    }
}
