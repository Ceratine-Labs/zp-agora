<?php

namespace Modules\Partners\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → Trading rules → Suppliers (T024), over
 * agora.usp_Partners_GridSuppliers.
 *
 * One row per supplier per site from PumpIT's BRN_Suppliers. The list is
 * group-wide — 2,994 codes repeated at 31 sites, 91,850 rows on
 * 12 Nov 2026 — so a row is (site, code), as PumpIT keys it: each site's copy
 * carries its own POS account number and its own cash, stock and active
 * flags, and those are what differ. Pick sites with the branches selector,
 * or a code in its header filter to see one supplier everywhere.
 *
 * Read-only. Contact, telephone, cell and email are near-empty on the live
 * list (31, 0, 0 and 0 rows filled), so they start hidden.
 */
class SupplierGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.suppliers';
    }

    public function title(): string
    {
        return 'Suppliers';
    }

    public function blurb(): ?string
    {
        return 'Every supplier (creditor) at every site, as PumpIT holds them. A code is the same supplier '
            .'at every site; the POS account number and the cash and stock flags are each site\'s own.';
    }

    public function defaultSort(): ?string
    {
        return 'SupplierCode';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('SupplierCode', 'Code', sort: 'SupplierCode', mono: true, link: true, filter: 'text',
                title: 'BRN_Suppliers.SupplierCode — the same supplier at every site. Opens the supplier.'),
            new GridColumn('SupplierName', 'Supplier', sort: 'SupplierName', wide: true),
            new GridColumn('CodeSeries', 'Series', format: 'chip', sort: 'CodeSeries', options: ['APS', 'APE', 'Other'],
                title: 'APS codes are mostly stock suppliers, APE mostly expense creditors'),
            new GridColumn('AccountNo', 'POS account', sort: 'AccountNo', mono: true,
                title: 'BRN_Suppliers.SupplierID — the account the site\'s till pays out against. Per site.'),
            new GridColumn('IsStock', 'Stock', format: 'bool', options: ['Yes', 'No'],
                title: 'Supplies stock (IsStock)'),
            new GridColumn('IsCash', 'Cash', format: 'bool', options: ['Yes', 'No'],
                title: 'Paid in cash from the till at this site (IsCash) — what the cashup\'s Suppliers leg offers'),
            new GridColumn('Status', 'Status', format: 'chip', sort: 'Status', options: ['Active', 'Inactive']),
            new GridColumn('Department', 'Department', sort: 'Department'),
            new GridColumn('ContactPerson', 'Contact', sort: 'ContactPerson', visible: false),
            new GridColumn('CashOrCredit', 'Cash or credit', visible: false,
                title: 'BRN_Suppliers.XCashOrCredit, as typed'),
            new GridColumn('Telephone', 'Telephone', visible: false),
            new GridColumn('CellNo', 'Cell', visible: false),
            new GridColumn('EmailAddress', 'Email', visible: false),
            new GridColumn('PosSystemId', 'POS system', format: 'number', sort: 'PosSystemId', visible: false),
            new GridColumn('IsSdkLoaded', 'Loaded to accounts', format: 'bool', options: ['Yes', 'No'], visible: false,
                title: 'Whether PumpIT has loaded the row to the accounting SDK (isSDKLoaded)'),
            new GridColumn('CreateDateTime', 'Created in PumpIT', format: 'datetime', sort: 'CreateDateTime',
                filter: 'none', visible: false, title: 'PumpIT clock'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Partners_GridSuppliers', acceptsFilters: true);
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.suppliers.show', ['branch' => $row->BranchId, 'code' => trim((string) $row->SupplierCode)]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    public function rowClass(object $row): ?string
    {
        return (int) ($row->IsActive ?? 1) === 0 ? 'is-warn' : null;
    }
}
