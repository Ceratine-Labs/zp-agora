<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * agora.UserActivity is UTC: ActivityLogger passes now(), and
 * usp_Core_LogActivity falls back to SYSUTCDATETIME() when nothing is passed.
 * A source check, because a test server on UTC cannot tell the two clocks apart.
 */
class ActivityClockTest extends TestCase
{
    public function test_the_activity_log_falls_back_to_the_utc_clock(): void
    {
        $sql = (string) file_get_contents(base_path('Modules/Core/Database/Procedures/usp_Core_LogActivity.sql'));
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sql);

        $this->assertMatchesRegularExpression('~DECLARE\s+@now\s+DATETIME2\(0\)\s*=\s*ISNULL\(@OccurredAt,\s*SYSUTCDATETIME\(\)\)~i', $code);
        $this->assertDoesNotMatchRegularExpression('~\b(SYSDATETIME|GETDATE|CURRENT_TIMESTAMP)\b~i', $code,
            'User activity is stamped on the application\'s UTC clock.');
    }

    public function test_forward_migration_only_redeploys_the_activity_procedure(): void
    {
        $sql = trim((string) file_get_contents(base_path('Modules/Core/Database/Procedures/usp_Core_LogActivity.sql')));

        DB::shouldReceive('unprepared')->once()->with($sql)->andReturn(true);

        $migration = require base_path('Modules/Core/Database/Migrations/v1__01pk_core_activity_clock.php');
        $migration->up();
    }
}
