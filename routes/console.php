<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| These declarations require one Agora-specific `schedule:run` cron. The
| production check on 8 November 2026 found none; installation requires
| Ryan's operational approval. See docs/deployment.md for the runtime user,
| proposed cron, log rotation, verification and rollback. ZP-NQL has its own
| scheduler on the same server and must remain unchanged.
|
| Command output is retained separately from the scheduler runner. Success
| and failure callbacks record the completed command in its dedicated log;
| launching a background command alone is not proof that it succeeded.
|
*/

$recordScheduledOutcome = static function (string $command, string $logFile, bool $succeeded): void {
    Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/'.$logFile),
        'level' => 'info',
    ])->log(
        $succeeded ? 'info' : 'error',
        $succeeded ? 'Scheduler command succeeded.' : 'Scheduler command failed.',
        ['command' => $command],
    );
};

/*
 * The stock master's "last counted" column (T025).
 *
 * Ryan asked for this to run when the count lines actually change rather than
 * on a fixed clock. `--if-stale` asks the data that question directly — one
 * MAX over STK_StockReconLine.CreateDateTime against the rollup's own
 * RefreshedAt — so this is hourly but almost always a no-op: it costs a single
 * scalar read on the twenty-three hours nothing landed, and does the real
 * four-second rebuild on the one that it did.
 *
 * Hourly rather than nightly because the load does not keep to a timetable —
 * on 20 September the newest count date was the 19th while its rows were
 * still being written at 19:44. withoutOverlapping because the rebuild takes
 * seconds against 6.2 million rows and two of them at once would be two
 * pointless passes, not a corruption.
 */
Schedule::command('agora:refresh-count-stats --if-stale')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-count-stats.log'))
    ->onSuccess(fn () => $recordScheduledOutcome('agora:refresh-count-stats --if-stale', 'scheduler-count-stats.log', true))
    ->onFailure(fn () => $recordScheduledOutcome('agora:refresh-count-stats --if-stale', 'scheduler-count-stats.log', false));

/*
 * Debtors statements: last month's statement to every account on the monthly
 * run, on config('debtors.run_day') at 06:00. Whether anything reaches a
 * debtor is config('debtors.send_mode') — 'off' makes this a no-op that says
 * so. Re-running it is safe: an account already sent its statement for the
 * period by the run is skipped.
 */
Schedule::command('debtors:send-statements')
    ->monthlyOn((int) config('debtors.run_day', 1), '06:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-debtors.log'))
    ->onSuccess(fn () => $recordScheduledOutcome('debtors:send-statements', 'scheduler-debtors.log', true))
    ->onFailure(fn () => $recordScheduledOutcome('debtors:send-statements', 'scheduler-debtors.log', false));
