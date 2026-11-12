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
                'help' => 'The number itself is what the bank\'s and the device supplier\'s settlements arrive under, so it is not changed from here.'],
            'IsActive' => ['label' => 'Active', 'type' => 'bool', 'column' => 'IsActive', 'optional' => true,
                'help' => 'Decides which site PumpIT assigns this number\'s deposits to. Refused while the number is active at another site.'],
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

    /**
     * A cash-device number has no active flag. On the others the flag's
     * words in the confirmation say, in plain words, where the number's
     * deposits land (Ryan, 12 Nov 2026): sp_Check_SMARTATM_TerminalNo assigns
     * a file to a site only while exactly one active row carries its number.
     */
    public function fieldsFor(object $row): array
    {
        $fields = $this->editableFields();
        if (($row->Kind ?? null) === 'Cash device terminal') {
            unset($fields['IsActive']);

            return $fields;
        }

        $what = ($row->Kind ?? null) === 'Smart ATM terminal' ? 'Smart ATM deposits' : 'settlements';
        $number = trim((string) ($row->Number ?? ''));
        $site = (string) ($row->BranchName ?? 'this site');
        $elsewhere = trim((string) ($row->ActiveElsewhere ?? ''));

        $fields['IsActive']['on'] = "On ({$what} under {$number} are assigned to {$site})";
        $fields['IsActive']['off'] = $elsewhere === ''
            ? "Off ({$what} under {$number} are left unassigned)"
            : "Off ({$what} under {$number} go to {$elsewhere})";
        $fields['IsActive']['help'] = "Switched on, PumpIT assigns {$what} under {$number} to {$site}. Switched off, they are left "
            .($elsewhere === '' ? 'unassigned.' : "to {$elsewhere}, where it is also active.")
            .' Refused while the number is active at another site.';

        return $fields;
    }
}
