<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return array_map(fn (string $name): string => $this->path().'/'.$name.'.sql', [
            'usp_StockRecon_WaterMeterSites',
            'usp_StockRecon_GridWaterMeters',
            'usp_StockRecon_GridVariance',
            'usp_StockRecon_PreviewBalancing',
            'usp_StockRecon_Commit',
            'usp_StockRecon_ResetToOriginal',
        ]);
    }
};
