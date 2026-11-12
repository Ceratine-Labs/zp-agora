<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Trading rules → Expense codes (T022): PumpIT's BRN_Expenses, per branch. */
class ExpenseGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'expense-codes';
    }

    public function resource(): string
    {
        return 'expenses';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridExpenses';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_Expenses';
    }

    public function noun(): string
    {
        return 'Expense code';
    }

    public function eyebrow(): string
    {
        return 'Setup · Trading rules';
    }

    public function title(): string
    {
        return 'Expense codes';
    }

    public function blurb(): ?string
    {
        return 'Expense codes, their GL account, VAT treatment and who approves them. The approver decides whose queue a purchase request lands in.';
    }

    public function defaultSort(): ?string
    {
        return 'ExpenseNo';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('ExpenseNo', 'Expense no', format: 'number', sort: 'ExpenseNo'),
            new GridColumn('ExpenseDescription', 'Description', sort: 'ExpenseDescription', link: true, wide: true),
            new GridColumn('GLCode', 'GL code', sort: 'GLCode', mono: true),
            new GridColumn('Vat', 'VAT %', format: 'number', sort: 'Vat'),
            new GridColumn('ApproverName', 'Approver', sort: 'ApproverName'),
            new GridColumn('ApproverUserId', 'Approver id', format: 'number', visible: false),
            new GridColumn('IsAsset', 'Asset', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No']),
        ];
    }

    public function meanings(): array
    {
        return [
            'Vat' => 'The VAT rate PumpIT applies: 15 or 0.',
            'ApproverName' => 'Who approves a purchase request against this code (PumpIT\'s SS_Users).',
            'IsAsset' => 'PumpIT\'s asset flag. It was 0 on every code on 12 Nov 2026, those named "… ASSET" included.',
        ];
    }
}
