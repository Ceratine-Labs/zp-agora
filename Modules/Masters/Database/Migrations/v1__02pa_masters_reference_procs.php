<?php

use App\Support\Database\ProcedureMigration;

/**
 * The reference masters' procedures (T022): one read-only grid procedure per
 * master, over the views v1__02a creates. None of them writes anything.
 * Deployed after v1__02p, which already ran with T021's five.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Masters_GridProfitCentres.sql',
        ];
    }
};
