<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Source checks catch a clock regression even when the test server uses UTC. */
class UserImportClockTest extends TestCase
{
    public function test_import_audit_stamps_use_the_applications_utc_clock(): void
    {
        $sql = (string) file_get_contents(base_path('Modules/Core/Database/Procedures/usp_Core_MigrateUsers.sql'));
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sql);

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertMatchesRegularExpression('~DECLARE\s+@now\s+DATETIME2\(0\)\s*=\s*SYSUTCDATETIME\(\)~i', $code);
        $this->assertDoesNotMatchRegularExpression(
            '~\b(SYSDATETIME|GETDATE|SYSDATETIMEOFFSET|CURRENT_TIMESTAMP)\b~i',
            $code,
            'User import audit timestamps must use the same UTC clock as Eloquent.',
        );
    }

    public function test_forward_migration_only_redeploys_the_user_import_procedure(): void
    {
        $sql = trim((string) file_get_contents(base_path('Modules/Core/Database/Procedures/usp_Core_MigrateUsers.sql')));

        // An exact single batch also prevents a historical data rewrite or
        // redeploy of unrelated Core procedures joining this clock correction.
        DB::shouldReceive('unprepared')->once()->with($sql)->andReturn(true);

        $migration = require base_path('Modules/Core/Database/Migrations/v1__01ph_core_user_import_clock.php');
        $migration->up();
    }
}
