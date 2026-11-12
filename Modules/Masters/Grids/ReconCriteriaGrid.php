<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Statement criteria (T022): PumpIT's dbo.SS_ReconCriteria, per branch. Read-only. */
class ReconCriteriaGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'statement-criteria';
    }

    public function resource(): string
    {
        return 'reconcriteria';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridReconCriteria';
    }

    public function legacySource(): string
    {
        return 'dbo.SS_ReconCriteria';
    }

    public function noun(): string
    {
        return 'Statement criterion';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Statement criteria';
    }

    public function blurb(): ?string
    {
        return 'The older bank-recon criteria: where the reference and the recon key sit on a statement line, per tender group. The auto-recon rules in use are edited under Bank reconciliation.';
    }

    public function defaultSort(): ?string
    {
        return 'GroupName';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('GroupName', 'Tender group', sort: 'GroupName'),
            new GridColumn('Code', 'Code', sort: 'Code', link: true, mono: true),
            new GridColumn('Description', 'Description', sort: 'Description', wide: true),
            new GridColumn('Reference', 'Reference', sort: 'Reference', mono: true),
            new GridColumn('ReferenceStartPosition', 'Reference from', format: 'number'),
            new GridColumn('ReferenceEndPosition', 'Reference to', format: 'number'),
            new GridColumn('ReconStartPosition', 'Recon key from', format: 'number'),
            new GridColumn('ReconEndPosition', 'Recon key to', format: 'number'),
            new GridColumn('IsAutoReconcile', 'Auto reconcile', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('GroupTable', 'Tender table', mono: true, visible: false),
            new GridColumn('CriteriaId', 'Criteria id', format: 'number', sort: 'CriteriaId', visible: false),
        ];
    }

    public function meanings(): array
    {
        return [
            'Reference' => 'Where the bank statement line names the account: characters Reference from .. to.',
            'IsAutoReconcile' => 'PumpIT\'s own flag. The auto-recon rules Agora runs are BRN_AutoReconCriteria, edited under Control → Money → Bank reconciliation.',
        ];
    }
}
