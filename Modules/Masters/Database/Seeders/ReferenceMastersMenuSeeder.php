<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The reference masters' navigation (T022).
 *
 * Core seeded Setup's placeholders for several of these with no route —
 * The business → Profit centres, Categories and GP bands, Standard
 * categories; Trading rules → Expense codes; People and assets → Utility
 * meters. This ATTACHES the route to each, keeping Core's label, place and
 * sort, rather than adding a second link. MenuService::item() overwrites
 * every field it is given and nulls the rest, so all of them are given.
 *
 * The masters that had no slot — tills, shifts, transaction types, fuel
 * types, tanks, pumps, merchant and terminal numbers, the older recon
 * criteria — go under one new column, "Sites and forecourt".
 *
 * Counting areas (STK_Area) and the auto-recon rules (BRN_AutoReconCriteria)
 * are NOT here: Stock recon's counting areas and Recon's rule editor already
 * own them, and Core's Counting areas entry already points at the former.
 *
 * Each entry carries its route's permission, so it is hidden from anybody who
 * could not open it. Upserts on (workspace, section, path).
 */
class ReferenceMastersMenuSeeder extends Seeder
{
    /** After Core's (30) and the branch master's (32). */
    public int $seedOrder = 33;

    /**
     * path => [parent, label, slug, permission resource, sort]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    public const ITEMS = [
        'business/profit-centres' => ['business', 'Profit centres', 'profit-centres', 'profitcentres', 20],
        'business/categories' => ['business', 'Categories and GP bands', 'categories', 'categorygl', 30],
        'business/standard-categories' => ['business', 'Standard categories', 'standard-categories', 'standardcategories', 40],
        'trading-rules/expense-codes' => ['trading-rules', 'Expense codes', 'expense-codes', 'expenses', 60],
        'site-setup/tills' => ['site-setup', 'Tills', 'tills', 'tills', 10],
        'site-setup/shifts' => ['site-setup', 'Shifts', 'shifts', 'shifts', 20],
        'site-setup/transaction-types' => ['site-setup', 'Transaction types', 'transaction-types', 'transactiontypes', 30],
        'site-setup/fuel-types' => ['site-setup', 'Fuel types', 'fuel-types', 'fueltypes', 40],
        'site-setup/tanks' => ['site-setup', 'Tanks', 'tanks', 'tanks', 50],
        'site-setup/pumps' => ['site-setup', 'Pumps', 'pumps', 'pumps', 60],
        'people-assets/meters' => ['people-assets', 'Utility meters', 'meters', 'meters', 30],
        'site-setup/terminals' => ['site-setup', 'Merchant and terminal numbers', 'terminals', 'terminals', 70],
        'site-setup/statement-criteria' => ['site-setup', 'Statement criteria', 'statement-criteria', 'reconcriteria', 80],
    ];

    public function run(): void
    {
        // The one column Core did not seed: the masters with no placeholder.
        MenuService::item('ho', 'setup', ['path' => 'site-setup', 'label' => 'Sites and forecourt', 'sort' => 25]);

        foreach (self::ITEMS as $path => [$parent, $label, $slug, $resource, $sort]) {
            MenuService::item('ho', 'setup', [
                'path' => $path,
                'parent' => $parent,
                'label' => $label,
                'route' => "app.setup.{$slug}.index",
                'permission' => "masters.{$resource}.view",
                'sort' => $sort,
            ]);
        }
    }
}
