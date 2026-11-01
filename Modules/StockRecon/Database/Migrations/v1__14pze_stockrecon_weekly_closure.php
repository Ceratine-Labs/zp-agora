<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return array_map(fn ($name) => $this->path().'/usp_StockRecon_'.$name.'.sql', [
            'SaveWeeklyCase', 'GetWeeklyCase', 'GridWeeklyCases', 'GetWeeklyOverview',
        ]);
    }
};
