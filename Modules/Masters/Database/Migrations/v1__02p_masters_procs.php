<?php

use App\Support\Database\ProcedureMigration;

/**
 * Masters' procedures (T021): the branch master listing, one branch, its save,
 * and the regions / brands / formats behind it. Each reads and writes Agora's
 * own tables only.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Masters_GridBranches.sql',
            $this->path().'/usp_Masters_GetBranch.sql',
            $this->path().'/usp_Masters_SaveBranch.sql',
            $this->path().'/usp_Masters_GetBranchGroupings.sql',
            $this->path().'/usp_Masters_SaveBranchGrouping.sql',
        ];
    }
};
