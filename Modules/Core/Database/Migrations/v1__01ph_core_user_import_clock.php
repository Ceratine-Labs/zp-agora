<?php

use App\Support\Database\ProcedureMigration;

/**
 * Align new user-import audit stamps with Agora's UTC application clock.
 *
 * Existing User audit stamps have both PHP and procedure writers, with no
 * per-row clock provenance. Rewriting them would guess which values need a
 * shift, so this only corrects future imports and leaves historical values.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [$this->path().'/usp_Core_MigrateUsers.sql'];
    }
};
