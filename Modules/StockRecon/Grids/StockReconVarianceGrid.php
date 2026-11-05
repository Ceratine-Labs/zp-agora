<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Original vs amended variance — every count line in a period, its variance
 * on the quantities as counted beside its variance on the quantities as
 * balancing has amended them, and what balancing moved.
 *
 * Asked for by ZRP on 19 Oct 2026, for the managers after a week is balanced:
 * "view variances based on original and amended quantities for an extended
 * period (not locked to a specific number of days) … to easily see the
 * difference in figures before and after balancing". A run is locked to one
 * Monday–Sunday week; this is not a run. It reads PumpIT's count lines
 * directly, so it shows a period balanced by Agora, by hand in PumpIT, or not
 * at all, with the same arithmetic PumpIT's own Stock Recon Balancing screen
 * uses (see the procedure's header).
 *
 * THE TWO BLOCKS ARE TOLD APART BY THEIR HEADINGS, "Original …" and
 * "Amended …", rather than by a spanning row above them. The shell's header row
 * also carries the filters, and a person can reorder and hide columns, which a
 * spanning band cannot follow. Each side also has its own header colour.
 * Original reads first, as on the stock recon chain panel. Raw quantities
 * are available in Columns, leaving the default comparison compact.
 *
 * The totals in the strip above the grid come out of the procedure's third
 * result set — every line that matched, not the page — which is why totals()
 * names keys that are not columns.
 */
class StockReconVarianceGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.variance';
    }

    public function title(): string
    {
        return 'Original vs amended variance';
    }

    public function blurb(): ?string
    {
        return 'Each count line\'s variance as counted beside its variance after balancing. Red is short, '
            .'yellow is over by more than the item\'s allowance. Change in variance is the amended value less '
            .'the original.';
    }

    public function cellsPartial(): string
    {
        return 'stockrecon::grid._variance-cells';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftNo', label: 'Shift', format: 'number'),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'AreaDescription', label: 'Area', sort: 'AreaDescription'),
            new GridColumn(key: 'StockLocation', label: 'Location', visible: false),
            new GridColumn(key: 'POSCode', label: 'POS code', sort: 'POSCode', mono: true, visible: false),
            new GridColumn(key: 'StockItemNo', label: 'Item no', mono: true, visible: false),
            new GridColumn(key: 'StockItemDescription', label: 'Item', sort: 'StockItemDescription', wide: true,
                link: true, title: 'Opens the item in the stock master'),
            new GridColumn(key: 'SellPrice', label: 'Price', format: 'money', sort: 'SellPrice', visible: false),

            // Keep the comparison on screen; raw quantities remain in Columns.
            new GridColumn(key: 'Balancing', label: 'Balancing', format: 'chip', sort: 'Balancing',
                title: 'Amended: an open, issue, close or POS quantity differs from what was counted'),
            new GridColumn(key: 'ValueVarMoved', label: 'Change in variance', format: 'money', sort: 'ValueVarMoved',
                total: true, title: 'Amended value less original value, including authorized waste'),

            new GridColumn(key: 'QtyOpenOriginal', label: 'Original open', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyIssuedOriginal', label: 'Original issued', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyCloseOriginal', label: 'Original close', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyComputerOriginal', label: 'Original POS', format: 'qty', compactQuantity: true, total: true, visible: false),
            new GridColumn(key: 'QtyVarOriginal', label: 'Original variance', format: 'qty', compactQuantity: true, sort: 'QtyVarOriginal',
                total: true, title: 'POS - (open + issued - close), on the quantities as counted'),
            new GridColumn(key: 'ValueVarOriginal', label: 'Original value', format: 'money', sort: 'ValueVarOriginal',
                total: true),

            new GridColumn(key: 'QtyOpen', label: 'Amended open', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyIssued', label: 'Amended issued', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyClose', label: 'Amended close', format: 'qty', compactQuantity: true, visible: false),
            new GridColumn(key: 'QtyComputer', label: 'Amended POS', format: 'qty', compactQuantity: true, total: true, visible: false),
            new GridColumn(key: 'QtyVarB4Waste', label: 'Variance before waste', format: 'qty', compactQuantity: true, visible: false,
                title: 'The amended variance before any waste adjustment'),
            new GridColumn(key: 'QtyGoodWaste', label: 'Good waste', format: 'qty', compactQuantity: true, visible: false,
                title: 'Captured good waste, which may differ from the authorized quantity.'),
            new GridColumn(key: 'QtyAuthorizedGoodWaste', label: 'Authorized good waste', format: 'qty', compactQuantity: true, visible: false,
                title: 'Good waste approved by operations against this captured quantity.'),
            new GridColumn(key: 'QtyVar', label: 'Amended variance', format: 'qty', compactQuantity: true, sort: 'QtyVar', total: true,
                title: 'Amended variance with authorized good waste applied to shortages only'),
            new GridColumn(key: 'ValueVar', label: 'Amended value', format: 'money', sort: 'ValueVar', total: true),
            new GridColumn(key: 'QtyVarMoved', label: 'Quantity moved', format: 'qty', compactQuantity: true, visible: false, total: true),
        ];
    }

    /**
     * Keyed by column. Only the columns the procedure filters on: a filter it
     * did not know would be silently ignored, and a filter that does nothing
     * is worse than none.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['BranchName', 'AreaDescription', 'StockLocation', 'POSCode', 'StockItemNo', 'StockItemDescription'] as $text) {
            $filters[$text] = new GridFilter(column: $text, type: 'text');
        }

        foreach (['ShiftNo', 'QtyVarOriginal', 'ValueVarOriginal', 'QtyVar', 'ValueVar', 'ValueVarMoved'] as $number) {
            $filters[$number] = new GridFilter(column: $number, type: 'number');
        }

        $filters['TransactionDate'] = new GridFilter(column: 'TransactionDate', type: 'date');
        $filters['Balancing'] = new GridFilter(column: 'Balancing', type: 'set', options: ['Amended', 'As counted']);

        // Separate scope inputs leave the typed header filters independent.
        // Both normalise to the existing procedure's filtering contract.
        $filters['Area'] = new GridFilter(column: 'AreaDescription', type: 'text');
        $filters['RemainingVariance'] = new GridFilter(column: 'QtyVar', type: 'number');

        return $filters;
    }

    /**
     * The footer's columns and the strip's figures, all from the procedure's
     * third result set.
     *
     * @return array<int, string>
     */
    public function totals(): array
    {
        return array_merge(parent::totals(), [
            'Lines', 'LinesAmended', 'ExcludedMeterRows',
            'ValueShortOriginal', 'ValueOverOriginal',
            'ValueShort', 'ValueOver',
        ]);
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_StockRecon_GridVariance',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => self::allowedBranchIds()],
        );
    }

    public function defaultSort(): ?string
    {
        // The procedure's own order, PumpIT's: each item's shifts in sequence.
        return null;
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function branchSelector(): bool
    {
        return true;
    }

    /** The item, in the stock master (feature-rules §3.7). */
    public function rowUrl(object $row): ?string
    {
        if (! isset($row->BranchId, $row->StockItemNo)) {
            return null;
        }

        return route('app.master.stock.show', ['branch' => $row->BranchId, 'item' => $row->StockItemNo]);
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
