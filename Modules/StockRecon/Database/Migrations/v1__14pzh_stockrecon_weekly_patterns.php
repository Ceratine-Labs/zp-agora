<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return array_map(fn ($name) => $this->path().'/usp_StockRecon_'.$name.'.sql', [
            'WeeklyPatterns', 'AuthorizeWeeklyCase', 'CreateWeeklyPattern', 'GetWeeklyOverview', 'GetWeeklyCase', 'SaveWeeklyCase', 'GridWeeklyCases',
        ]);
    }
};
