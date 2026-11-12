<?php

use App\Support\Database\ProcedureMigration;

/**
 * usp_Core_LogActivity falls back to SYSUTCDATETIME(), not SYSDATETIME(), when
 * no @OccurredAt is passed — agora.UserActivity is UTC, like every instant
 * Agora writes (slot 01pk).
 *
 * Deploys only that procedure. Existing rows are left as they are: every
 * writer so far passes @OccurredAt in UTC, so none took the fallback.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [$this->path().'/usp_Core_LogActivity.sql'];
    }
};
