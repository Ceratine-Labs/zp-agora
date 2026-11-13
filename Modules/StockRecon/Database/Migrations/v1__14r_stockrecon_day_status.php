<?php

use App\Support\Database\MigrationHelper;
use App\Support\Database\ProcedureMigration;

/**
 * Stock recon: is each day reconciled? (Ryan, 13 Nov 2026.)
 *
 * agora.usp_StockRecon_DayStatus reads Agora's own run ledger and the PumpIT
 * recon lines and writes nothing: per site per day, green (every counting area
 * posted, nothing left open), orange (partly), red (counted, nothing posted),
 * grey (no counts, or not over); and, for one day, its areas, the lines that
 * keep it open and every run over it. No table changes.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        parent::up();

        MigrationHelper::recordVersion(
            '1.46',
            'Stock recon: reconciled days — usp_StockRecon_DayStatus answers per site per day whether every counting '
            .'area is covered by a posted run with nothing left open, and what keeps a day open.'
        );
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_DayStatus.sql'];
    }
};
