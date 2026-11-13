{{--
    The day calendar, as the fragment the stock recon centre fetches after it
    shows (ReconDayController::calendar). One row per site; a single site
    reads as two bands of the month.

    Props: $rows, $month, $prev, $next, $legend
--}}
<x-day-calendar :rows="$rows" :month="$month" :prev="$prev" :next="$next" :legend="$legend"
                :href="fn ($row, $date) => route('app.stockrecon.days.show', ['branch' => $row['branchId'], 'day' => $date->toDateString()])" />
<p class="field-help" style="margin:8px 0 0">
    The latest posted run decides each counting area's day. Click a coloured day for what keeps it open.
    <span class="mono">agora.usp_StockRecon_DayStatus</span>
</p>
