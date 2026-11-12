<?php

use App\Support\Database\ProcedureMigration;

/**
 * Reports (T027): Master data changes names the overrides Agora holds over
 * PumpIT's rows — stock lines, critical lines and bank recon rules — whose
 * procedures now write agora.MasterChange (Product v1__05pd, Recon v1__13pzg).
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Reports_GridMasterChanges.sql'];
    }
};
