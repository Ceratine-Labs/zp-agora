<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;

/** Setup → Sites and forecourt → Transaction types (T022): PumpIT's dbo.BRN_TransactionType, per branch. Read-only. */
class TransactionTypeGrid extends ReferenceMasterGrid
{
    public function slug(): string
    {
        return 'transaction-types';
    }

    public function resource(): string
    {
        return 'transactiontypes';
    }

    public function procedureName(): string
    {
        return 'agora.usp_Masters_GridTransactionTypes';
    }

    public function legacySource(): string
    {
        return 'dbo.BRN_TransactionType';
    }

    public function noun(): string
    {
        return 'Transaction type';
    }

    public function eyebrow(): string
    {
        return 'Setup · Sites and forecourt';
    }

    public function title(): string
    {
        return 'Transaction types';
    }

    public function blurb(): ?string
    {
        return 'The purchase-request transaction types each site numbers its documents under.';
    }

    public function defaultSort(): ?string
    {
        return 'TypeCode';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchName', 'Site', sort: 'BranchName'),
            new GridColumn('TypeCode', 'Code', sort: 'TypeCode', mono: true),
            new GridColumn('TypeDescription', 'Transaction type', sort: 'TypeDescription', link: true, wide: true),
            new GridColumn('NumberPrefix', 'Number prefix', sort: 'NumberPrefix', mono: true),
            new GridColumn('LastNumber', 'Last number issued', format: 'number', sort: 'LastNumber'),
            new GridColumn('CreateDateTime', 'Created in PumpIT', format: 'datetime', filter: 'none', visible: false),
        ];
    }

    public function meanings(): array
    {
        return [
            'NumberPrefix' => 'PumpIT\'s AlpNum: the letters a document number starts with.',
            'LastNumber' => 'PumpIT\'s HighNum: the last number issued under this type at this site.',
        ];
    }

    public function writeEntity(): ?string
    {
        return 'TransactionType';
    }

    public function saveProcedure(): ?string
    {
        return 'agora.usp_Masters_SaveTransactionType';
    }

    public function editableFields(): array
    {
        return [
            'TypeDescription' => ['label' => 'Name', 'type' => 'text', 'column' => 'TypeDescription', 'max' => 50,
                'help' => 'The name PumpIT\'s purchase requests show. The code, the number prefix and the last number issued are PumpIT\'s and stay as they are.'],
        ];
    }
}
