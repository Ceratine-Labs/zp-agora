<?php

namespace Modules\Product\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → Trading rules → Critical lines (T025).
 *
 * The lines that must never be out of stock: 1,895 across 15 of the 22 sites,
 * 1,677 of them active. On 20 September 2026, **709 active critical lines were
 * at or below zero on hand** — which is what this screen is for, and why the
 * default order puts them at the top rather than sorting alphabetically.
 *
 * IT IS NOT A VIEW OF THE STOCK MASTER. The list is keyed to the POS file:
 * every one of the 1,895 has a row in DBF_STDB, and 602 of them — a third —
 * have no stock master row at all. Those are real products the site sells that
 * nobody counts, so `Counted` is a COLUMN here rather than a precondition.
 */
class CriticalLineGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.master.critical';
    }

    public function title(): string
    {
        return 'Critical lines';
    }

    public function blurb(): ?string
    {
        return 'The lines that must never be out of stock, and whether they are. Keyed to the '
            .'site\'s POS file rather than to the stock master — a third of this list is not counted, '
            .'which is a fact about the list rather than a gap in it.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'Description', label: 'Line', sort: 'Description', wide: true, link: true),
            new GridColumn(key: 'PosCode', label: 'POS code', sort: 'PosCode', mono: true),
            new GridColumn(key: 'PosSystem', label: 'POS system', sort: 'PosSystem'),
            new GridColumn(key: 'Category', label: 'Category', sort: 'Category'),

            // The answer. Out of stock · Low · In stock · No POS record · Off the list.
            new GridColumn(key: 'Status', label: 'Status', format: 'chip'),
            new GridColumn(key: 'QtyOnHand', label: 'On hand', format: 'number', sort: 'QtyOnHand'),

            new GridColumn(
                key: 'IsCounted',
                label: 'Counted',
                format: 'bool',
                title: 'Whether this line is also on the stock master and therefore appears on a counting sheet. A third of the critical list is not.',
            ),
            new GridColumn(key: 'LastSoldAt', label: 'Last sold', format: 'date', sort: 'LastSoldAt'),

            new GridColumn(key: 'PackSize', label: 'Pack', format: 'number', visible: false),
            new GridColumn(key: 'CostPrice', label: 'Cost (excl)', format: 'money', visible: false),
            new GridColumn(key: 'PosSellPrice', label: 'POS sell (excl)', format: 'money', visible: false),
            new GridColumn(key: 'StockItemNo', label: 'Item no', mono: true, visible: false),
            new GridColumn(key: 'AreaDescription', label: 'Counting area', visible: false),
            new GridColumn(key: 'IsActive', label: 'On the list', format: 'bool', visible: false),
            new GridColumn(
                key: 'Source',
                label: 'Held by',
                visible: false,
                title: 'Whether this line is the customer\'s own (legacy) or an Agora override.',
            ),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', visible: false),
        ];
    }

    /**
     * Keyed by COLUMN, which is what the header row looks each one up by.
     *
     * The set options are VALUES the column actually holds — the status words
     * the procedure composes, and the two POS systems the live list really
     * uses. It carries only ARCH and WINBRANCH today; the other four are
     * offered anyway, because a site adopting AURA should not find the filter
     * silently unable to describe it.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'Description' => new GridFilter(column: 'Description', type: 'text'),
            'PosCode' => new GridFilter(column: 'PosCode', type: 'text'),
            'Category' => new GridFilter(column: 'Category', type: 'text'),
            'PosSystem' => new GridFilter(column: 'PosSystem', type: 'set', options: [
                'ARCH', 'WINBRANCH', 'AURA', 'NAMOS', 'PILOT', 'ARCHLIQ',
            ]),
            'Status' => new GridFilter(column: 'Status', type: 'set', options: [
                'Out of stock', 'Low', 'In stock', 'No POS record', 'Off the list',
            ]),
            'IsCounted' => new GridFilter(column: 'IsCounted', type: 'set', options: ['Yes', 'No']),
            'Source' => new GridFilter(column: 'Source', type: 'set', options: ['legacy', 'agora']),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Product_GridCriticalLines', acceptsFilters: true);
    }

    /**
     * None — and that is the point.
     *
     * Leaving this null lets the procedure apply its own default, which puts
     * Out of stock first, then Low. A critical-lines screen sorted
     * alphabetically makes somebody scroll to find the emergency.
     */
    public function defaultSort(): ?string
    {
        return null;
    }

    /** A critical line belongs to one site; the list differs at every one. */
    public function branchSelector(): bool
    {
        return true;
    }

    public function rowUrl(object $row): ?string
    {
        // The stock master line behind it, where there is one. A third of
        // this list has none, and for those the row simply does not link —
        // GridDefinition renders a null rowUrl as plain text, which is
        // feature-rules §3.7 and better than a link to a 404.
        if ($row->StockItemNo === null) {
            return null;
        }

        return route('app.master.stock.show', [
            'branch' => $row->BranchId,
            'item' => $row->StockItemNo,
        ]);
    }
}
