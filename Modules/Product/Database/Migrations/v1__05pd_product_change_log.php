<?php

use App\Support\Database\ProcedureMigration;

/**
 * Product (T027): the stock line and critical line writes keep a per-field
 * change log. usp_Product_SaveStockItem, usp_Product_SetStockItemFlag and
 * usp_Product_SaveCriticalLine each write one agora.MasterChange row per field
 * they moved — before and after as in force, the user and the reason — inside
 * the transaction of the write, so they show in Setup → Governance → Master
 * data changes.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [
            $this->path().'/usp_Product_SaveStockItem.sql',
            $this->path().'/usp_Product_SetStockItemFlag.sql',
            $this->path().'/usp_Product_SaveCriticalLine.sql',
        ];
    }
};
