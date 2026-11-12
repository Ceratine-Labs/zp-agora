<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Merchant and terminal numbers (T022): PumpIT's dbo.SS_Branch_ABSA_MerchantNo, SS_Branch_SMARTATM_TerminalNo, SS_Branch_CASHDEVICE_TerminalNo, per branch. Read-only. */
class TerminalGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'terminals';
    }

    public function resource(): string
    {
        return 'terminals';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridTerminals';
    }

    public function legacySource(): string
    {
        return 'dbo.SS_Branch_ABSA_MerchantNo, SS_Branch_SMARTATM_TerminalNo, SS_Branch_CASHDEVICE_TerminalNo';
    }

    public function noun(): string
    {
        return 'Merchant or terminal number';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Merchant and terminal numbers';
    }

    public function blurb(): ?string
    {
        return 'The ABSA merchant numbers, Smart ATM terminals and cash-device terminals each site settles under.';
    }

    public function defaultSort(): ?string
    {
        return 'Kind';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('Kind', 'Kind', format: 'chip', sort: 'Kind', options: ['ABSA merchant', 'Smart ATM terminal', 'Cash device terminal']),
            new GridColumn('Number', 'Number', sort: 'Number', link: true, mono: true),
            new GridColumn('Description', 'Description', sort: 'Description', wide: true),
            new GridColumn('Active', 'Active', format: 'chip', sort: 'Active', options: ['Yes', 'No', 'Not recorded']),
        ];
    }

    public function meanings(): array
    {
        return [
            'Active' => 'The cash-device table records no active flag, so it says Not recorded rather than guessing.',
        ];
    }
}
