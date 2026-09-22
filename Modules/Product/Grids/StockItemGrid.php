<?php

namespace Modules\Product\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → Trading rules → Stock recon master (T025).
 *
 * The per-branch product listing: 7,447 lines across 22 sites, each one the
 * customer's row from `PumpIT.dbo.STK_StockMaster` unless Agora holds a live
 * override for it. What the screen shows is whichever of the two is in force,
 * and the `Source` column says which.
 *
 * WHAT IT SHOWS THAT THE MASTER DOES NOT HOLD. Cost, gross profit, quantity on
 * hand, category and last-sold all come from the POS cost file, and last
 * counted from a rollup over 6.2 million count lines. None of them is on the
 * stock master itself — which is the single most surprising thing about the
 * legacy table, and the reason this screen is worth building rather than
 * pointing people at SSMS.
 *
 * THE DEFAULT LAYOUT IS THE ARGUMENT. Twenty-seven columns is too many to open
 * with, so eleven start visible and the rest are one click away in the column
 * chooser. The eleven are the ones that answer "is this line right": what it
 * is, where it is counted, what it costs, what it makes, and whether anybody
 * has counted it lately. The behaviour flags are real but they are rarely the
 * question, so they start hidden.
 */
class StockItemGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.master.stock';
    }

    public function title(): string
    {
        return 'Stock recon master';
    }

    public function blurb(): ?string
    {
        return 'Every stock line a site carries. Item numbers are per site — the same product '
            .'has a different number at each — and cost, GP and stock on hand come from that '
            .'site\'s POS file rather than from the master itself.';
    }

    public function columns(): array
    {
        return [
            // The item number is the row's identity, so it is the way in.
            new GridColumn(key: 'StockItemNo', label: 'Item', sort: 'StockItemNo', mono: true, link: true),
            new GridColumn(key: 'StockItemDescription', label: 'Description', sort: 'StockItemDescription', wide: true),
            new GridColumn(key: 'POSCode', label: 'POS code', sort: 'POSCode', mono: true),
            new GridColumn(key: 'PosSystem', label: 'POS system', sort: 'PosSystem'),
            new GridColumn(key: 'AreaDescription', label: 'Counting area', sort: 'AreaDescription'),
            new GridColumn(key: 'UOMCode', label: 'UOM', sort: 'UOMCode'),
            new GridColumn(key: 'PriceType', label: 'Price type', sort: 'PriceType'),

            // Selling price is VAT-inclusive and the POS pair is not; see the
            // procedure header. Both are here so the difference is visible.
            new GridColumn(key: 'SellingPrice', label: 'Sell (incl)', format: 'money', sort: 'SellingPrice'),
            new GridColumn(key: 'CostPrice', label: 'Cost (excl)', format: 'money', sort: 'CostPrice'),
            new GridColumn(key: 'GpPercent', label: 'GP %', format: 'pct', sort: 'GpPercent'),

            // The two counting states are the reason the screen exists, so
            // Status leads the hidden-by-default boundary rather than trailing
            // it.
            new GridColumn(key: 'Status', label: 'Status', format: 'chip'),

            new GridColumn(key: 'QtyOnHand', label: 'On hand', format: 'number', sort: 'QtyOnHand', visible: false),
            new GridColumn(key: 'Category', label: 'Category', sort: 'Category', visible: false),
            new GridColumn(key: 'LastSoldAt', label: 'Last sold', format: 'date', sort: 'LastSoldAt', visible: false),
            new GridColumn(key: 'QtySoldThisMonth', label: 'Sold this month', format: 'number', visible: false),
            new GridColumn(key: 'LastCountedAt', label: 'Last counted', format: 'date', sort: 'LastCountedAt', visible: false),
            new GridColumn(key: 'CountLines90', label: 'Counts (90d)', format: 'number', visible: false),
            new GridColumn(
                key: 'PricingFlag',
                label: 'Pricing',
                format: 'chip',
                visible: false,
                title: 'Why there is no GP, or why it should not be trusted: no POS record, no cost price, no POS sell price, or selling below cost.',
            ),
            new GridColumn(key: 'PosSellPrice', label: 'POS sell (excl)', format: 'money', visible: false),
            new GridColumn(key: 'IsCritical', label: 'Critical line', format: 'bool', visible: false),

            /*
             * THE SEVEN BEHAVIOUR FLAGS, which are what the batch action sets.
             *
             * They start hidden because they are rarely the question — but
             * every one of them is now settable across a ticked selection, and
             * a flag you can change in bulk and cannot see afterwards is a
             * change nobody can check. Tick a column on in the chooser and the
             * result of the last batch is on screen.
             *
             * IsActive is not here: it is what the Status column already says
             * as the word `Retired`, and two renderings of one bit invite the
             * reader to look for a difference between them.
             */
            new GridColumn(key: 'IsMonitoredItem', label: 'Monitored', format: 'bool', visible: false),
            new GridColumn(
                key: 'IsDoCloseQtyCalc',
                label: 'Close-qty calc',
                format: 'bool',
                visible: false,
                title: 'Whether the close quantity is calculated for this line. No server-side rule enforces it — see the save procedure.',
            ),
            new GridColumn(key: 'IsAllowNegativeQtyIssued', label: 'Neg. issue', format: 'bool', visible: false),
            new GridColumn(key: 'IsAllowNegativeQtyClose', label: 'Neg. close', format: 'bool', visible: false),
            new GridColumn(key: 'IsStockItemPreProduction', label: 'Pre-production input', format: 'bool', visible: false),
            new GridColumn(key: 'IsPreProductionItem', label: 'Pre-production item', format: 'bool', visible: false),
            new GridColumn(key: 'Factor', label: 'Factor', format: 'number', visible: false),
            new GridColumn(key: 'AreaGroup', label: 'Area group', visible: false),
            new GridColumn(key: 'AreaNo', label: 'Area no', format: 'number', visible: false),
            new GridColumn(
                key: 'Source',
                label: 'Held by',
                visible: false,
                title: 'Whether this row is the customer\'s own (legacy) or an Agora override.',
            ),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', visible: false),
        ];
    }

    /**
     * Keyed by COLUMN, which is the contract the base class states and what
     * the header row looks each column up by. A plain list renders no filters
     * at all and fails silently.
     *
     * GridFilter keeps VALUES, not labels, so every set option below is a
     * string the column actually holds — the six POS system names as PumpIT
     * spells them, the three price types with their spaces, and the four
     * status words the procedure composes. A labelled option would send the
     * label and match nothing.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'StockItemNo' => new GridFilter(column: 'StockItemNo', type: 'text'),
            'StockItemDescription' => new GridFilter(column: 'StockItemDescription', type: 'text'),
            'POSCode' => new GridFilter(column: 'POSCode', type: 'text'),
            'PosSystem' => new GridFilter(column: 'PosSystem', type: 'set', options: [
                'ARCH', 'WINBRANCH', 'AURA', 'NAMOS', 'PILOT', 'ARCHLIQ',
            ]),
            'AreaDescription' => new GridFilter(column: 'AreaDescription', type: 'text'),
            'UOMCode' => new GridFilter(column: 'UOMCode', type: 'set', options: ['Each', 'KG', 'LTR']),
            'PriceType' => new GridFilter(column: 'PriceType', type: 'set', options: [
                'Selling Price', 'Set Price', 'Factor',
            ]),
            'Category' => new GridFilter(column: 'Category', type: 'text'),
            'Status' => new GridFilter(column: 'Status', type: 'set', options: [
                'Active', 'Not counted in 90 days', 'Never counted', 'Retired',
            ]),
            'Source' => new GridFilter(column: 'Source', type: 'set', options: ['legacy', 'agora']),
        ];
    }

    public function source(): GridSource
    {
        // acceptsFilters: true — the procedure declares @FiltersJson and reads
        // it at $.column. Passing false and filtering in PHP is the split
        // feature-rules §3.2 exists to prevent.
        return new ProcedureSource('agora.usp_Product_GridStockItems', acceptsFilters: true);
    }

    public function defaultSort(): ?string
    {
        return 'StockItemNo';
    }

    /**
     * A stock item belongs to one site and the same number means a different
     * product at the next one, so the branch selector is not a convenience
     * here — without it head office reads 7,447 rows in which "item 10" is
     * twenty-two unrelated things.
     */
    public function branchSelector(): bool
    {
        return true;
    }

    /**
     * Ticking rows is what makes the batch flag action possible (Ryan,
     * 22 September 2026): "batch actions to toggle the booleans on them, set
     * them as active or inactive for each boolean type".
     */
    public function selectable(): bool
    {
        return true;
    }

    /**
     * BRANCH AND ITEM, because an item number alone is not a row.
     *
     * 653 numbers are reused across 22 sites, so with head office looking at
     * the whole estate a key of '10' names twenty-two unrelated products. The
     * batch endpoint splits this back into the pair and the procedure is keyed
     * on both.
     */
    public function rowKey(object $row): string|int|null
    {
        if (! isset($row->BranchId, $row->StockItemNo)) {
            return null;
        }

        return ((int) $row->BranchId).':'.$row->StockItemNo;
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.master.stock.show', [
            'branch' => $row->BranchId,
            'item' => $row->StockItemNo,
        ]);
    }
}
