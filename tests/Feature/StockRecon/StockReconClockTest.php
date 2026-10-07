<?php

namespace Tests\Feature\StockRecon;

use PHPUnit\Framework\TestCase;

/**
 * Every time a stock recon procedure writes is UTC (v1__14po, 7 Oct 2026).
 *
 * The application writes StockReconRun.CreatedAt in UTC (config app.timezone),
 * and until v1__14po the procedures stamped CommittedAt, the amendments and the
 * releases from SYSDATETIME(), which on ZP-MIST-SVR is +02:00 — every run read
 * two hours between preview and commit. The same defect, and the same test, as
 * Recon (ReconClockTest, v1__13py).
 *
 * Read from the procedure text: the local container now runs South African
 * time like the customer's (scripts/local-sql.sh), but a test that relied on
 * that would pass on any UTC box. A business date (CONVERT(date, GETDATE())) is
 * allowed by shape; stock recon has none today.
 */
class StockReconClockTest extends TestCase
{
    public function test_no_stock_recon_procedure_writes_a_time_from_the_servers_clock(): void
    {
        $files = glob(dirname(__DIR__, 3).'/Modules/StockRecon/Database/Procedures/*.sql') ?: [];
        $this->assertNotEmpty($files, 'The stock recon procedures were not found — the path moved.');

        $offenders = [];

        foreach ($files as $file) {
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', (string) file_get_contents($file));
            $code = (string) preg_replace('~CONVERT\s*\(\s*date\s*,\s*GETDATE\(\)\s*\)~i', '', $code);

            if (preg_match_all('~\b(SYSDATETIME|GETDATE|SYSDATETIMEOFFSET|CURRENT_TIMESTAMP)\b\s*(\(\))?~i', $code, $m) > 0) {
                $offenders[] = basename($file).': '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders,
            "A stock recon procedure reads the server's wall clock. Stamp times with SYSUTCDATETIME(), "
            .'the clock the application writes on.');
    }
}
