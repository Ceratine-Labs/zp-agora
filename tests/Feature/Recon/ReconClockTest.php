<?php

namespace Tests\Feature\Recon;

use PHPUnit\Framework\TestCase;

/**
 * Every time a recon procedure writes is UTC.
 *
 * The application writes ReconRun.CreatedAt in UTC (config app.timezone), and
 * until 24 Sep 2026 the procedures stamped CommittedAt and the batch, match and
 * stamp rows from SYSDATETIME(), which on ZP-MIST-SVR is +02:00. The run page
 * read "Committed 1 hour from now" (v1__13py).
 *
 * No test against the local container can catch the clock coming back: it runs
 * on UTC, where SYSDATETIME() and SYSUTCDATETIME() are the same value. So this
 * reads the procedure text instead. Two uses of the server's clock are left on
 * purpose and allowed here by shape:
 *
 *  · CONVERT(date, GETDATE()) — a default for a BUSINESS date, compared with
 *    LineDate / FromDate / ToDate, which are the customer's local dates. UTC
 *    would give yesterday between midnight and 02:00.
 *  · usp_Recon_SuggestMatches' elapsed-time counter — a duration, not a time.
 */
class ReconClockTest extends TestCase
{
    public function test_no_recon_procedure_writes_a_time_from_the_servers_clock(): void
    {
        $files = glob(dirname(__DIR__, 3).'/Modules/Recon/Database/Procedures/*.sql') ?: [];
        $this->assertNotEmpty($files, 'The recon procedures were not found — the path moved.');

        $offenders = [];

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);

            // Comments may explain the old clock; only code counts.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sql);

            $code = (string) preg_replace('~CONVERT\s*\(\s*date\s*,\s*GETDATE\(\)\s*\)~i', '', $code);

            if (basename($file) === 'usp_Recon_SuggestMatches.sql') {
                $code = (string) preg_replace([
                    '~@Started\s+datetime2\(3\)\s*=\s*SYSDATETIME\(\)~i',
                    '~DATEDIFF\s*\(\s*millisecond\s*,\s*@Started\s*,\s*SYSDATETIME\(\)\s*\)~i',
                ], '', $code);
            }

            if (preg_match_all('~\b(SYSDATETIME|GETDATE|SYSDATETIMEOFFSET|CURRENT_TIMESTAMP)\b\s*(\(\))?~i', $code, $m) > 0) {
                $offenders[] = basename($file).': '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A recon procedure reads the server's wall clock. Stamp times with SYSUTCDATETIME(), "
            .'the clock the application writes on.'
        );
    }
}
