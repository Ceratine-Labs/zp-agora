<?php

namespace Modules\Reports\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Gate;

/**
 * Setup → Governance → Master data changes (T027).
 *
 * Every change to a master, one row per field: Agora's own masters from
 * agora.MasterChange and PumpIT's, written back, from the master ledgers of
 * agora.vw_PumpItChange (MasterWrite, PeopleWrite, PartnersWrite). The
 * procedure is agora.usp_Reports_GridMasterChanges; it filters, sorts and
 * pages, and refuses without the view permission, so the extract follows the
 * same rule as the screen.
 *
 * Not a saved view of Control → Governance → Audit trail: that report reads
 * vw_PumpItChange alone — so it has no Agora-only change — and is a Reports
 * catalogue entry with no header filters. This grid reads the same view for
 * the PumpIT side, so the two cannot disagree about a write-back.
 *
 * A PumpIT row opens its correction in the audit trail's panel
 * (app.reports.audit.change) for someone who may read the audit trail; an
 * Agora row on a branch or setting goes to that record.
 */
class MasterChangeGrid extends GridDefinition
{
    public const PERMISSION = 'reports.master-changes.view';

    public const ENTITIES = [
        'Branch', 'Region', 'Brand', 'Format', 'Setting', 'Stock line', 'Critical line', 'Recon rule',
        'Category band', 'Profit centre', 'Standard category', 'Expense code', 'Till', 'Shift',
        'Transaction type', 'Fuel type', 'Tank', 'Pump', 'Utility meter', 'Merchant or terminal number',
        'Employee', 'Employee area', 'Employee post', 'Supplier', 'Customer',
    ];

    public const SOURCES = ['Agora', 'Agora (copied from PumpIT)', 'PumpIT write-back'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.setup.master-changes';
    }

    public function title(): string
    {
        return 'Master data changes';
    }

    public function blurb(): ?string
    {
        return 'Every change to a master, one field per row: Agora\'s own (branches, settings) and PumpIT\'s, written back '
            .'(categories, sites\' masters, employees, suppliers, customers). Times are SAST. The last 90 days unless you choose dates.';
    }

    public function defaultSort(): ?string
    {
        return 'ChangedAt';
    }

    public function defaultDirection(): string
    {
        return 'desc';
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function detailMode(): string
    {
        return 'panel';
    }

    public function columns(): array
    {
        return [
            new GridColumn('ChangedAt', 'When', format: 'datetime', sort: 'ChangedAt', title: 'SAST'),
            new GridColumn('ChangedBy', 'Who', sort: 'ChangedBy'),
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('Entity', 'Master', sort: 'Entity', filter: 'set', options: self::ENTITIES),
            new GridColumn('RowKey', 'Record', sort: 'RowKey', wide: true, link: true),
            new GridColumn('Field', 'Field', sort: 'Field', mono: true),
            new GridColumn('PriorValue', 'Before'),
            new GridColumn('NewValue', 'After'),
            new GridColumn('Reason', 'Why', wide: true),
            new GridColumn('Source', 'Source', format: 'chip', sort: 'Source', options: self::SOURCES),
            new GridColumn('State', 'State', format: 'chip', sort: 'State', options: ['active', 'reversed']),
            new GridColumn('Action', 'Action', sort: 'Action', visible: false),
            new GridColumn('Change', 'Before → after', wide: true, visible: false),
            new GridColumn('PumpItTable', 'PumpIT table', sort: 'PumpItTable', mono: true, visible: false),
            new GridColumn('Ref', 'Press', mono: true, visible: false),
            new GridColumn('Ledger', 'Ledger', filter: 'set', options: ['MasterChange', 'MasterWrite', 'PeopleWrite', 'PartnersWrite'], visible: false),
            new GridColumn('LedgerId', 'Ledger row', format: 'number', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource('agora.usp_Reports_GridMasterChanges', acceptsFilters: true, extra: [
            'Authorized' => (int) Gate::allows(self::PERMISSION),
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
        ]);
    }

    /** The record changed, where it has a page of its own (feature-rules §3.7). */
    public function rowUrl(object $row): ?string
    {
        if (($row->Ledger ?? null) !== 'MasterChange') {
            return $this->detailUrl($row);
        }

        return match ($row->Entity ?? null) {
            'Branch' => Gate::allows('masters.branches.view')
                ? route('app.setup.branches.show', ['branch' => (int) $row->BranchId]) : null,
            'Setting' => Gate::allows('setup.settings.view') && isset($row->RowKey)
                ? route('app.setup.settings.show', ['key' => (string) $row->RowKey]) : null,
            default => null,
        };
    }

    /** A write-back opens the way the audit trail opens it. */
    public function detailUrl(object $row): ?string
    {
        if (($row->Ledger ?? 'MasterChange') === 'MasterChange' || ! Gate::allows('reports.audit-trail.view')) {
            return null;
        }

        return route('app.reports.audit.change', ['ledger' => (string) $row->Ledger, 'id' => (int) $row->LedgerId]);
    }
}
