<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Bank accounts (T022): the customer's bankrecon.BankAccount, per branch. Read-only. */
class BankAccountGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'bank-accounts';
    }

    public function resource(): string
    {
        return 'bankaccounts';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridBankAccounts';
    }

    public function legacySource(): string
    {
        return 'bankrecon.BankAccount';
    }

    public function noun(): string
    {
        return 'Bank account';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Bank accounts';
    }

    public function blurb(): ?string
    {
        return 'The account each site banks each method of payment into, as the customer set them up for bank reconciliation.';
    }

    public function defaultSort(): ?string
    {
        return 'PaymentType';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('PaymentType', 'Method of payment', format: 'chip', sort: 'PaymentType',
                options: ['ABSA', 'CashDeposit', 'CashMachine', 'Debtors', 'Direct', 'FleetCard', 'FNB', 'SmartATM', 'Suppliers', 'Yumbi']),
            new GridColumn('AccountNumber', 'Account number', sort: 'AccountNumber', link: true, mono: true),
            new GridColumn('BankName', 'Bank', sort: 'BankName'),
            new GridColumn('AccountName', 'Account name', sort: 'AccountName', wide: true),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('IsDeleted', 'Deleted', format: 'bool', options: ['Yes', 'No'], visible: false),
            new GridColumn('MethodOfPaymentId', 'Method id', format: 'number', visible: false),
            new GridColumn('CreatedAt', 'Set up', format: 'datetime', filter: 'none', visible: false),
        ];
    }

    public function meanings(): array
    {
        return [
            'PaymentType' => 'The tender the account receives (bankrecon.BankAccount.Type).',
            'IsDeleted' => 'The customer marks an account deleted rather than removing it. Deleted ones are listed only when this filter asks for them.',
        ];
    }
}
