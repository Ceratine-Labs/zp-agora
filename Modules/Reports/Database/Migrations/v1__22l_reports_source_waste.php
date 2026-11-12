<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return array_map(fn (string $name): string => $this->path().'/'.$name.'.sql', [
            'usp_Reports_GridMyQueue', 'usp_Reports_GridWaste',
        ]);
    }
};
