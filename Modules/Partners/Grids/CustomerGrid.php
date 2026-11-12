<?php

namespace Modules\Partners\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;

/**
 * Setup → Trading rules → Account customers (T024), over
 * agora.usp_Partners_GridCustomers.
 *
 * One row per account customer per site from PumpIT's BRN_Customers, with the
 * till's own record of the same account beside it. The credit limit and the
 * cell number are kept on the TILL (DBF_CLDB_CUSTOMER, through the Debtors
 * module's vw_DebtorAccount) and are empty on PumpIT's list — 286 limits and
 * 80 cell numbers against 0 and 0 on 12 Nov 2026 — so the till's are the
 * visible columns and PumpIT's are one click away, each labelled.
 *
 * Opens on the trading accounts; `controls=1` adds the payment-control
 * accounts (IsMOP — Cash Deposits, Zapper, Pumpit Control …), and the flag
 * travels in the query string so the extract and a pasted link agree.
 *
 * Where a statement goes is the Debtors module's (agora.DebtorContact) and is
 * edited on the debtor's statement. Read-only here.
 */
class CustomerGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.customers';
    }

    public function title(): string
    {
        return 'Account customers';
    }

    public function blurb(): ?string
    {
        return self::includesControls()
            ? 'Every account customer at every site, with the payment-control accounts PumpIT books methods of payment against.'
            : 'Every account customer (debtor) at every site, with the till\'s limit and where the statement goes.';
    }

    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('CustomerCode', 'Code', sort: 'CustomerCode', mono: true, link: true, filter: 'text',
                title: 'BRN_Customers.CustomerCode — per site. Opens the customer.'),
            new GridColumn('CustomerName', 'Customer', sort: 'CustomerName', wide: true),
            new GridColumn('AccountNo', 'Till account', sort: 'AccountNo', mono: true,
                title: 'BRN_Customers.DebtorID — the debtor account at the site\'s till'),
            new GridColumn('OnTill', 'On the till', format: 'bool', options: ['Yes', 'No'],
                title: 'Whether the till\'s debtor master (DBF_CLDB_CUSTOMER) has the account'),
            new GridColumn('TillCreditLimit', 'Credit limit', format: 'money', sort: 'TillCreditLimit',
                title: 'The till\'s limit (DBF_CLDB_CUSTOMER.CRLIMIT)'),
            new GridColumn('TillCellNo', 'Cell', title: 'The till\'s cell number (DBF_CLDB_CUSTOMER.TELC)'),
            new GridColumn('ContactPerson', 'Contact'),
            new GridColumn('StatementEmail', 'Statement to', sort: 'StatementEmail',
                title: 'Where the statement goes — Agora\'s list, edited on the debtor\'s statement'),
            new GridColumn('OnMonthlyRun', 'Monthly run', format: 'bool', options: ['Yes', 'No'],
                title: 'On the Debtors monthly statement run'),
            new GridColumn('Kind', 'Kind', format: 'chip', sort: 'Kind', options: ['Account', 'Payment control'],
                visible: self::includesControls()),
            new GridColumn('TillName', 'Name at the till', sort: 'TillName', visible: false),
            new GridColumn('IsBlocked', 'Blocked at the till', format: 'bool', options: ['Yes', 'No'], visible: false),
            new GridColumn('CreditLimit', 'Limit in PumpIT', format: 'money', sort: 'CreditLimit', visible: false,
                title: 'BRN_Customers.CreditLimitAmount — 0 on every live row (12 Nov 2026)'),
            new GridColumn('CellNo', 'Cell in PumpIT', visible: false,
                title: 'BRN_Customers.CellNo — empty on every live row (12 Nov 2026)'),
            new GridColumn('GroupAccountNo', 'Group account', mono: true, visible: false),
            new GridColumn('VatNo', 'VAT number', visible: false),
            new GridColumn('GlCode', 'GL code', mono: true, visible: false),
            new GridColumn('PosSystemId', 'POS system', format: 'number', visible: false),
            new GridColumn('CreateDateTime', 'Created in PumpIT', format: 'datetime', sort: 'CreateDateTime',
                filter: 'none', visible: false, title: 'PumpIT clock'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Partners_GridCustomers', acceptsFilters: true, extra: [
            'IncludeControls' => (int) self::includesControls(),
        ]);
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.customers.show', ['branch' => $row->BranchId, 'code' => trim((string) $row->CustomerCode)]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    public static function includesControls(): bool
    {
        return request()->boolean('controls');
    }
}
