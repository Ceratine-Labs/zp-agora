<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [
            $this->path().'/usp_Core_GridRoles.sql',
            $this->path().'/usp_Core_GridUsers.sql',
        ];
    }
};
