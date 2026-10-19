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
 * spanning band cannot follow. Original reads first, as on the stock recon
 * chain panel.
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
            .'yellow is over by more than the item\'s allowance. Moved by balancing is the amended value less '
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
            new GridColumn(key: 'POSCode', label: 'POS code', sort: 'POSCode', mono: true),
            new GridColumn(key: 'StockItemNo', label: 'Item no', mono: true, visible: false),
            new GridColumn(key: 'StockItemDescription', label: 'Item', sort: 'StockItemDescription', wide: true,
                link: true, title: 'Opens the item in the stock master'),
            new GridColumn(key: 'SellPrice', label: 'Price', format: 'money', sort: 'SellPrice', visible: false),

            // What balancing did to the line, ahead of the two blocks, so it is
            // on the screen before anybody scrolls: the grid is 25 columns and
            // the amended block starts past the right edge of a 1920 screen.
            new GridColumn(key: 'Balancing', label: 'Balancing', format: 'chip', sort: 'Balancing',
                title: 'Amended: an open, issue, close or POS quantity differs from what was counted'),
            new GridColumn(key: 'ValueVarMoved', label: 'Moved by balancing', format: 'money', sort: 'ValueVarMoved',
                total: true, title: 'Amended value less original value: what balancing changed on this line'),

            new GridColumn(key: 'QtyOpenOriginal', label: 'Original open', format: 'qty'),
            new GridColumn(key: 'QtyIssuedOriginal', label: 'Original issued', format: 'qty'),
            new GridColumn(key: 'QtyCloseOriginal', label: 'Original close', format: 'qty'),
            new GridColumn(key: 'QtyComputerOriginal', label: 'Original POS', format: 'qty', total: true),
            new GridColumn(key: 'QtyVarOriginal', label: 'Original variance', format: 'qty', sort: 'QtyVarOriginal',
                total: true, title: 'POS - (open + issued - close), on the quantities as counted'),
            new GridColumn(key: 'ValueVarOriginal', label: 'Original value', format: 'money', sort: 'ValueVarOriginal',
                total: true),

            new GridColumn(key: 'QtyOpen', label: 'Amended open', format: 'qty'),
            new GridColumn(key: 'QtyIssued', label: 'Amended issued', format: 'qty'),
            new GridColumn(key: 'QtyClose', label: 'Amended close', format: 'qty'),
            new GridColumn(key: 'QtyComputer', label: 'Amended POS', format: 'qty', total: true),
            new GridColumn(key: 'QtyVarB4Waste', label: 'Variance before waste', format: 'qty', visible: false,
                title: 'The amended variance before good waste is set against it'),
            new GridColumn(key: 'QtyGoodWaste', label: 'Good waste', format: 'qty', visible: false,
                title: 'Good waste recorded for the line. It can explain a short; it never makes an over.'),
            new GridColumn(key: 'QtyVar', label: 'Amended variance', format: 'qty', sort: 'QtyVar', total: true,
                title: 'POS - (open + issued - close) on the amended quantities, after good waste'),
            new GridColumn(key: 'ValueVar', label: 'Amended value', format: 'money', sort: 'ValueVar', total: true),
            new GridColumn(key: 'QtyVarMoved', label: 'Quantity moved', format: 'qty', visible: false, total: true),
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
            'Lines', 'LinesAmended',
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
