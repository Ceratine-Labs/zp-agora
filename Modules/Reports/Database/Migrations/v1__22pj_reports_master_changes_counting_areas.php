<?php

use App\Support\Database\ProcedureMigration;

/**
 * Reports (T027): Master data changes reads the counting areas' own ledger,
 * agora.StockAreaSettingChange — the prepared-food confirmations
 * usp_StockRecon_SaveCountingArea writes — beside agora.MasterChange.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Reports_GridMasterChanges.sql'];
    }
};
