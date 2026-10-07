<?php

namespace Modules\Assets\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\Assets\Services\AssetsService;

/**
 * Asset movements (T057): transfers, trips to repair and back, sales,
 * trade-ins and write-offs — reversed ones included, marked, because a
 * movement taken back is exactly the row an auditor looks for.
 *
 * Newest first by default (the procedure's own order). The date range bounds
 * the movement date and defaults to the last 90 days.
 */
class AssetMovementGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.assets.movement';
    }

    public function title(): string
    {
        return 'Asset movements';
    }

    public function blurb(): ?string
    {
        return 'Assets transferred, sent for repair and back, sold, traded in or written off. '
            .'A reversed movement stays here, marked, with who took it back and why.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'MovementRef', label: 'Movement', sort: 'MovementRef', mono: true),
            new GridColumn(key: 'MovementDate', label: 'Date', format: 'date', sort: 'MovementDate'),
            new GridColumn(key: 'AssetNo', label: 'Asset no', sort: 'AssetNo', mono: true, link: true),
            new GridColumn(key: 'Make', label: 'Make'),
            new GridColumn(key: 'Model', label: 'Model'),
            new GridColumn(key: 'AssetTypeDescription', label: 'Type', visible: false, filter: 'none'),
            new GridColumn(key: 'MovementType', label: 'Code', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'MovementTypeDescription', label: 'Movement type', sort: 'MovementTypeDescription', filter: 'set',
                options: ['Moved Between Sites', 'Repairs & Maintenance', 'Returned from repair', 'Sold', 'Traded In', 'Written Off']),
            new GridColumn(key: 'FromSite', label: 'From', sort: 'FromSite'),
            new GridColumn(key: 'ToSite', label: 'To', sort: 'ToSite'),
            new GridColumn(key: 'Proceeds', label: 'Proceeds', format: 'money', sort: 'Proceeds', total: true),
            new GridColumn(key: 'Reason', label: 'Reason', wide: true),
            new GridColumn(key: 'State', label: 'State', format: 'chip', sort: 'State', filter: 'set',
                options: ['In force', 'Reversed']),
            new GridColumn(key: 'CapturedBy', label: 'Captured by'),
            new GridColumn(key: 'BranchName', label: 'Belongs to', visible: false, filter: 'none'),
            new GridColumn(key: 'ReverseReason', label: 'Why reversed', wide: true, visible: false, filter: 'none'),
            new GridColumn(key: 'CapturedAt', label: 'Captured at', format: 'instant', visible: false, filter: 'none'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Assets_GridMovements',
            acceptsFilters: true,
            extra: ['AllowedBranchIds' => app(AssetsService::class)->allowedBranchIds()],
        );
    }

    public function cellsPartial(): string
    {
        return 'assets::grid._cells';
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function branchSelector(): bool
    {
        return true;
    }

    /** The asset the movement belongs to. */
    public function rowUrl(object $row): ?string
    {
        return route('app.assets.register.show', ['branch' => $row->BranchId, 'asset' => $row->AssetId]);
    }
}
