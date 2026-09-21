<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| ⚠ NOTHING RUNS THIS YET. There is no `schedule:run` cron on
| agora.ceratine.com — docs/deployment.md does not mention one because until
| now Agora had nothing to schedule. A line here is a declaration of intent
| until that cron exists, and the screens say how old their data is precisely
| so that a schedule which is not running is visible rather than silent.
|
| To turn it on, on the server:
|     * * * * * cd /path/to/agora && php artisan schedule:run >> /dev/null 2>&1
|
*/

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
    ->runInBackground();
