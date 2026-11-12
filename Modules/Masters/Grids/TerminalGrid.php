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

    public function writeEntity(): ?string
    {
        return 'Terminal';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveTerminal';
    }

    public function editableFields(): array
    {
        return [
            'Description' => ['label' => 'Description', 'type' => 'text', 'column' => 'Description', 'max' => 50,
                'help' => 'The number itself, and whether it is active, decide where the bank\'s and the device supplier\'s settlements land, so neither is changed from here.'],
        ];
    }

    public function writeTable(object $row): string
    {
        return match ($row->Kind ?? null) {
            'ABSA merchant' => 'dbo.SS_Branch_ABSA_MerchantNo',
            'Smart ATM terminal' => 'dbo.SS_Branch_SMARTATM_TerminalNo',
            'Cash device terminal' => 'dbo.SS_Branch_CASHDEVICE_TerminalNo',
            default => $this->legacySource(),
        };
    }
}
