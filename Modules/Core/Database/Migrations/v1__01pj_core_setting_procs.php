<?php

use App\Support\Database\ProcedureMigration;

/**
 * System defaults and thresholds (T026): the listing, one setting, its save,
 * and the one-off copy of PumpIT's SS_SysDefaults / BRN_Config. Each reads and
 * writes Agora's own tables only; the copy reads PumpIT through the two
 * agora.vw_Legacy* views v1__01k created.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Core_GridSettings.sql',
            $this->path().'/usp_Core_GetSetting.sql',
            $this->path().'/usp_Core_SaveSetting.sql',
            $this->path().'/usp_Migrate_Setting.sql',
        ];
    }
};
