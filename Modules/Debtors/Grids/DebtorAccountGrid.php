<?php

namespace Modules\Debtors\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * Debtors statements — one row per account per site (13 Oct 2026).
 *
 * The balance a statement for the period would show, what moved in it, and
 * where the statement would go. A row opens the account's statement, which is
 * where it is checked, downloaded and sent.
 *
 * The period is the grid's date range and defaults to LAST MONTH in the
 * procedure: a statement is for a month that has finished. The sites are the
 * branches selector at head office and the pinned site in the branch
 * workspace — "per site" either way.
 */
class DebtorAccountGrid extends GridDefinition
{
    public const STATUSES = ['On the monthly run', 'Not on the monthly run', 'No email'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.debtors.index';
    }

    public function title(): string
    {
        return 'Debtors statements';
    }

    public function blurb(): ?string
    {
        return 'Every account with a balance or a posting in the period, from the till\'s own debtors ledger. '
            .'A row opens its statement.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'AccountNo', label: 'Account', sort: 'AccountNo', mono: true, link: true,
                title: 'The account number at the till. Opens the statement.'),
            new GridColumn(key: 'AccountName', label: 'Name', sort: 'AccountName', wide: true),
            new GridColumn(key: 'Opening', label: 'Brought forward', format: 'money', sort: 'Opening', total: true,
                title: 'Every posting before the period. Negative = paid ahead.'),
            new GridColumn(key: 'Debits', label: 'Charged', format: 'money', sort: 'Debits', total: true,
                title: 'Invoices, debit notes and debit journals in the period'),
            new GridColumn(key: 'Credits', label: 'Paid and credited', format: 'money', sort: 'Credits', total: true,
                title: 'Payments, credit notes and credit journals in the period'),
            new GridColumn(key: 'Closing', label: 'Balance', format: 'money', sort: 'Closing', total: true,
                title: 'Brought forward + charged - paid and credited. Negative = in credit.'),
            new GridColumn(key: 'LastPayment', label: 'Last paid', format: 'date', sort: 'LastPayment'),
            new GridColumn(key: 'Email', label: 'Email', sort: 'Email'),
            new GridColumn(key: 'Status', label: 'Statement', format: 'chip', sort: 'Status',
                title: 'On the monthly run = mailed by the run on the 1st. Whether mail reaches anybody is the send mode.'),
            new GridColumn(key: 'LastSentAt', label: 'Last mailed', format: 'instant', sort: 'LastSentAt',
                title: 'The last statement mailed to the debtor itself — test sends are not counted'),
            new GridColumn(key: 'LastActivity', label: 'Last posting', format: 'date', sort: 'LastActivity', visible: false),
            new GridColumn(key: 'Postings', label: 'Postings', format: 'number', visible: false,
                title: 'Postings in the period'),
            new GridColumn(key: 'CcEmail', label: 'Copies to', visible: false),
        ];
    }

    /** @return array<string, GridFilter> */
    public function filters(): array
    {
        return [
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'AccountNo' => new GridFilter(column: 'AccountNo', type: 'text'),
            'AccountName' => new GridFilter(column: 'AccountName', type: 'text'),
            'Email' => new GridFilter(column: 'Email', type: 'text'),
            'Closing' => new GridFilter(column: 'Closing', type: 'number'),
            'Status' => new GridFilter(column: 'Status', type: 'set', options: self::STATUSES),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Debtors_GridAccounts',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => self::allowedBranchIds()],
        );
    }

    public function defaultSort(): ?string
    {
        // The procedure's own order: site, then name.
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

    /** The account's statement, for the period the grid is showing. */
    public function rowUrl(object $row): ?string
    {
        return route('app.debtors.show', [
            'branch' => $row->BranchId,
            'account' => $row->AccountNo,
            'from' => substr((string) $row->PeriodFrom, 0, 10),
            'to' => substr((string) $row->PeriodTo, 0, 10),
        ]);
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
